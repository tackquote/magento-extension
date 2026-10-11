<?php
/**
 * The order-limit plugins hand the checker the right quantities: the add request read as
 * Quote::addProduct() reads it, an API quantity change, and a cart-page update with removals.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;
use TackQuote\Quotes\Plugin\Checkout\OrderLimitUpdateItemsGuard;
use TackQuote\Quotes\Plugin\Quote\OrderLimitCartGuard;
use TackQuote\Quotes\Plugin\Quote\OrderLimitCartItemRepositoryGuard;
use TackQuote\Quotes\Plugin\Quote\OrderLimitSubmitGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\OrderLimitCartGuard
 * @covers \TackQuote\Quotes\Plugin\Quote\OrderLimitCartItemRepositoryGuard
 * @covers \TackQuote\Quotes\Plugin\Checkout\OrderLimitUpdateItemsGuard
 * @covers \TackQuote\Quotes\Plugin\Quote\OrderLimitSubmitGuard
 */
class OrderLimitGuardsTest extends TestCase
{
    private function product(string $type): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn($type);
        $product->method('getSku')->willReturn('A');
        $product->method('getName')->willReturn('Widget');

        return $product;
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function requests(): array
    {
        return [
            'no request' => [null, 1.0],
            'a number' => [12, 12.0],
            'a numeric string' => ['3', 3.0],
            'a DataObject' => [new DataObject(['qty' => 25]), 25.0],
            'a DataObject without qty' => [new DataObject([]), 1.0],
            'zero' => [0, 1.0],
            'garbage' => [['qty' => 4], 1.0],
        ];
    }

    /**
     * @dataProvider requests
     * @param mixed $request
     */
    public function testTheAddedQuantityIsReadAsAddProductReadsIt($request, float $expected): void
    {
        self::assertSame($expected, OrderLimitCartGuard::requestedQty($request));
    }

    public function testAnAddOfASimpleProductIsChecked(): void
    {
        $quote = $this->createMock(Quote::class);
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::once())->method('assertAdd')->with($quote, 'A', 7.0, 'Widget');

        (new OrderLimitCartGuard($checker))->beforeAddProduct($quote, $this->product('simple'), 7);
    }

    public function testAConfigurableAddIsLeftToTheLaterChecks(): void
    {
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::never())->method('assertAdd');

        (new OrderLimitCartGuard($checker))->beforeAddProduct($this->createMock(Quote::class), $this->product('configurable'), 7);
    }

    public function testAnApiQuantityChangeOnAnExistingLineIsChecked(): void
    {
        $cart = $this->createMock(Quote::class);
        $cart->method('getItemById')->with(4)->willReturn(new DataObject(['id' => 4]));
        $carts = $this->createMock(CartRepositoryInterface::class);
        $carts->method('get')->with(11)->willReturn($cart);
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn(4);
        $item->method('getQuoteId')->willReturn('11');
        $item->method('getQty')->willReturn(60);
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::once())->method('assertItemQuantities')->with($cart, [4 => 60.0]);

        (new OrderLimitCartItemRepositoryGuard($checker, $carts))
            ->beforeSave($this->createMock(CartItemRepositoryInterface::class), $item);
    }

    public function testANewApiLineIsLeftToTheAddGuard(): void
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn(null);
        $carts = $this->createMock(CartRepositoryInterface::class);
        $carts->expects(self::never())->method('get');
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::never())->method('assertItemQuantities');

        (new OrderLimitCartItemRepositoryGuard($checker, $carts))
            ->beforeSave($this->createMock(CartItemRepositoryInterface::class), $item);
    }

    public function testTheCartPageUpdateIsCheckedWithRemovalsAsZero(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getItemById')->willReturnCallback(static function ($id) {
            return in_array((int) $id, [1, 2, 3], true) ? new DataObject(['id' => $id]) : false;
        });
        $cart = $this->createMock(Cart::class);
        $cart->method('getQuote')->willReturn($quote);
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::once())->method('assertItemQuantities')->with($quote, [1 => 0.0, 2 => 30.0, 3 => 0.0]);

        (new OrderLimitUpdateItemsGuard($checker))->beforeUpdateItems($cart, [
            1 => ['qty' => 5, 'remove' => 1],
            2 => ['qty' => '30'],
            3 => ['qty' => 0],
            99 => ['qty' => 4],
            4 => 'junk',
        ]);
    }

    public function testTheOrderSubmitChecksTheWholeCart(): void
    {
        $quote = $this->createMock(Quote::class);
        $checker = $this->createMock(OrderLimitChecker::class);
        $checker->expects(self::once())->method('assertCart')->with($quote);

        (new OrderLimitSubmitGuard($checker))->beforeSubmit($this->createMock(QuoteManagement::class), $quote);
    }
}
