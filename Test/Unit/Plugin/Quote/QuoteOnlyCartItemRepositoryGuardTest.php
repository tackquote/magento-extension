<?php
/**
 * The cart-item service contract (REST/SOAP/GraphQL): an EXISTING line may not grow.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartItemRepositoryGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartItemRepositoryGuard
 */
class QuoteOnlyCartItemRepositoryGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var CartRepositoryInterface&MockObject */
    private $carts;

    /** @var CartItemRepositoryInterface&MockObject */
    private $repository;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->carts = $this->createMock(CartRepositoryInterface::class);
        $this->repository = $this->createMock(CartItemRepositoryInterface::class);
    }

    private function guard(): QuoteOnlyCartItemRepositoryGuard
    {
        return new QuoteOnlyCartItemRepositoryGuard($this->mode, $this->carts);
    }

    /**
     * The cart holds line 7: product 9, quantity $currentQty.
     */
    private function cartWithLine(float $currentQty): void
    {
        $line = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQty'])
            ->getMock();
        $line->method('getQty')->willReturn($currentQty);
        $line->setData('product_id', 9);

        $cart = $this->createMock(Quote::class);
        $cart->method('getItemById')->with(7)->willReturn($line);
        $this->carts->method('get')->with(11)->willReturn($cart);
    }

    /**
     * @param int|null $itemId
     */
    private function posted($itemId, float $qty): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getQuoteId')->willReturn('11');
        $item->method('getQty')->willReturn($qty);

        return $item;
    }

    public function testRaisingAnExistingLineOverTheApiIsRefusedWhereTheModeApplies(): void
    {
        // CartItemPersister sets the quantity directly on an existing line and never calls
        // Quote::addProduct(), so without this a pre-existing cart could be inflated over REST.
        $this->mode->method('isActive')->willReturn(true);
        $this->cartWithLine(1.0);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeSave($this->repository, $this->posted(7, 50.0));
    }

    public function testRaisingAQuoteOnlyProductsLineIsRefusedInNormalMode(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('isProductIdQuoteOnly')->with(9)->willReturn(true);
        $this->cartWithLine(1.0);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeSave($this->repository, $this->posted(7, 2.0));
    }

    public function testLoweringAnExistingLineIsAllowed(): void
    {
        $this->mode->method('isActive')->willReturn(true);
        $this->cartWithLine(5.0);

        $this->guard()->beforeSave($this->repository, $this->posted(7, 2.0));

        self::assertTrue(true);
    }

    public function testANewLineIsLeftToTheAddProductGuard(): void
    {
        // A new line reaches Quote::addProduct(), where QuoteOnlyCartGuard decides; this
        // plugin does not load the cart for it.
        $this->mode->method('isActive')->willReturn(true);
        $this->carts->expects(self::never())->method('get');

        $this->guard()->beforeSave($this->repository, $this->posted(null, 3.0));

        self::assertTrue(true);
    }

    public function testRaisingAnOrdinaryLineInANormalStoreIsAllowed(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('isProductIdQuoteOnly')->willReturn(false);
        $this->cartWithLine(1.0);

        $this->guard()->beforeSave($this->repository, $this->posted(7, 9.0));

        self::assertTrue(true);
    }
}
