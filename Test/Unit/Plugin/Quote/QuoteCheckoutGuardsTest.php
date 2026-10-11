<?php
/**
 * The guards around an accepted quote in the cart: nothing added beside it, its quantities
 * locked on the cart page and over the Web API, and an order only from the intact quote.
 *
 * Mutation proof:
 *   P1 the mixing guard ignores quoted lines             -> testNothingIsAddedBesideAnAcceptedQuote
 *   P2 the mixing guard refuses the builder's own adds   -> testTheBuildersOwnAddsPassAndAnOrdinaryCartIsUntouched
 *   P3 a changed quantity passes the cart page            -> testTheCartPageCannotChangeAQuotedQuantity
 *   P4 a changed quantity passes the Web API              -> testTheWebApiCannotChangeAQuotedQuantity
 *   P5 the submit guard lets a broken quoted cart through -> testABrokenQuotedCartIsNeverOrdered
 *   P6 the page guard proceeds with a broken quoted cart  -> testTheCheckoutPageTakesABrokenQuoteOut
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartGuard;
use TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartItemRepositoryLock;
use TackQuote\Quotes\Plugin\Quote\QuoteCheckoutPageGuard;
use TackQuote\Quotes\Plugin\Quote\QuoteCheckoutSubmitGuard;
use TackQuote\Quotes\Plugin\Quote\QuoteCheckoutUpdateItemsLock;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartGuard
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteCheckoutUpdateItemsLock
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartItemRepositoryLock
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteCheckoutSubmitGuard
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteCheckoutPageGuard
 */
class QuoteCheckoutGuardsTest extends TestCase
{
    private const MARKER = [
        'tackquote_quote_ref' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
        'tackquote_quote_number' => 'TK-2026-000123',
        'tackquote_quoted_price' => 10.0,
        'tackquote_quoted_qty' => 3,
        'tackquote_quote_lines' => 1,
        'tackquote_quote_currency' => 'USD',
    ];

    /** @var int[] */
    private $removed = [];

    private function line(int $id, bool $quoted, array $data = []): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem'])
            ->getMock();
        $item->method('getParentItem')->willReturn(null);
        $item->setData(array_merge([
            'id' => $id,
            'qty' => 3,
            'custom_price' => 10.0,
            'additional_data' => $quoted ? json_encode(self::MARKER) : null,
        ], $data));

        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function quote(array $items): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'getItemById', 'removeItem', 'collectTotals'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getItemById')->willReturnCallback(static function ($id) use ($items) {
            foreach ($items as $item) {
                if ((int) $item->getId() === (int) $id) {
                    return $item;
                }
            }

            return false;
        });
        $quote->method('removeItem')->willReturnCallback(function ($id) use ($quote) {
            $this->removed[] = (int) $id;

            return $quote;
        });
        $quote->method('collectTotals')->willReturnSelf();
        $quote->setData('base_currency_code', 'USD');
        $quote->setData('quote_currency_code', 'USD');

        return $quote;
    }

    private function quotedCart(): QuotedCart
    {
        return new QuotedCart(new QuotedLine(new Json()));
    }

    public function testNothingIsAddedBesideAnAcceptedQuote(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('checked out on its own');

        (new QuoteCheckoutCartGuard($this->quotedCart()))
            ->beforeAddProduct($this->quote([$this->line(1, true)]), $this->createMock(Product::class), 1);
    }

    public function testTheBuildersOwnAddsPassAndAnOrdinaryCartIsUntouched(): void
    {
        $cart = $this->quotedCart();
        $cart->startBuilding();
        (new QuoteCheckoutCartGuard($cart))
            ->beforeAddProduct($this->quote([$this->line(1, true)]), $this->createMock(Product::class), 1);
        (new QuoteCheckoutCartGuard($this->quotedCart()))
            ->beforeAddProduct($this->quote([$this->line(1, false)]), $this->createMock(Product::class), 1);

        self::assertTrue(true);
    }

    private function cartModel(Quote $quote): Cart
    {
        $cart = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $cart->method('getQuote')->willReturn($quote);

        return $cart;
    }

    public function testTheCartPageCannotChangeAQuotedQuantity(): void
    {
        $lock = new QuoteCheckoutUpdateItemsLock(new QuotedLine(new Json()));
        $cart = $this->cartModel($this->quote([$this->line(1, true), $this->line(2, false)]));

        // Unchanged quantities (the cart page posts every line), a removal, and an ordinary line pass.
        $lock->beforeUpdateItems($cart, [1 => ['qty' => '3'], 2 => ['qty' => '9']]);
        $lock->beforeUpdateItems($cart, [1 => ['qty' => '0']]);
        $lock->beforeUpdateItems($cart, [1 => ['qty' => '5', 'remove' => '1']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cannot be changed in the store');
        $lock->beforeUpdateItems($cart, [1 => ['qty' => '4']]);
    }

    public function testTheWebApiCannotChangeAQuotedQuantity(): void
    {
        $quote = $this->quote([$this->line(1, true)]);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('get')->willReturn($quote);
        $lock = new QuoteCheckoutCartItemRepositoryLock(new QuotedLine(new Json()), $repository);
        $subject = $this->createMock(CartItemRepositoryInterface::class);

        $same = $this->createMock(CartItemInterface::class);
        $same->method('getItemId')->willReturn(1);
        $same->method('getQuoteId')->willReturn(5);
        $same->method('getQty')->willReturn(3);
        $lock->beforeSave($subject, $same);

        $changed = $this->createMock(CartItemInterface::class);
        $changed->method('getItemId')->willReturn(1);
        $changed->method('getQuoteId')->willReturn(5);
        $changed->method('getQty')->willReturn(30);
        $this->expectException(LocalizedException::class);
        $lock->beforeSave($subject, $changed);
    }

    public function testABrokenQuotedCartIsNeverOrdered(): void
    {
        $guard = new QuoteCheckoutSubmitGuard($this->quotedCart());
        $management = $this->createMock(QuoteManagement::class);

        // Intact, and ordinary, carts pass.
        $guard->beforeSubmit($management, $this->quote([$this->line(1, true)]));
        $guard->beforeSubmit($management, $this->quote([$this->line(1, false)]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no longer matches the accepted quote');
        $guard->beforeSubmit($management, $this->quote([$this->line(1, true, ['qty' => 30])]));
    }

    /**
     * @return array{0: QuoteCheckoutPageGuard, 1: Redirect}
     */
    private function pageGuard(Quote $quote, int $messages): array
    {
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $session->method('getQuote')->willReturn($quote);
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $factory = $this->getMockBuilder(RedirectFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($redirect);
        $manager = $this->createMock(MessageManager::class);
        $manager->expects(self::exactly($messages))->method('addErrorMessage');

        return [
            new QuoteCheckoutPageGuard(
                $this->quotedCart(),
                $session,
                $this->createMock(CartRepositoryInterface::class),
                $factory,
                $manager
            ),
            $redirect,
        ];
    }

    public function testTheCheckoutPageTakesABrokenQuoteOut(): void
    {
        [$guard, $redirect] = $this->pageGuard($this->quote([$this->line(1, true, ['custom_price' => 1.0])]), 1);

        $result = $guard->aroundExecute($this->createMock(CheckoutIndex::class), static function () {
            return 'the checkout page';
        });

        self::assertSame($redirect, $result);
        self::assertSame([1], $this->removed);
    }

    public function testTheCheckoutPageOpensForAnIntactQuote(): void
    {
        [$guard] = $this->pageGuard($this->quote([$this->line(1, true)]), 0);

        $result = $guard->aroundExecute($this->createMock(CheckoutIndex::class), static function () {
            return 'the checkout page';
        });

        self::assertSame('the checkout page', $result);
        self::assertSame([], $this->removed);
    }
}
