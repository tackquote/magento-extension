<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Quote\QuoteOnlySubmitGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteOnlySubmitGuard
 */
class QuoteOnlySubmitGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var QuoteManagement&MockObject */
    private $management;

    /** @var Quote&MockObject */
    private $cart;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->management = $this->createMock(QuoteManagement::class);
        $this->cart = $this->createMock(Quote::class);
    }

    public function testPlacingAnOrderIsRefusedWhereTheModeApplies(): void
    {
        // Luma's REST payment-information call, GraphQL placeOrder and frontend payment
        // flows all end in QuoteManagement::submit(); a pre-existing cart must not convert.
        $this->mode->method('isActive')->willReturn(true);

        $this->expectException(LocalizedException::class);

        (new QuoteOnlySubmitGuard($this->mode, new QuotedCart(new QuotedLine(new Json()))))->beforeSubmit($this->management, $this->cart);
    }

    public function testACartHoldingAQuoteOnlyProductCannotBeConvertedInNormalMode(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('cartHasQuoteOnlyProduct')->with($this->cart)->willReturn(true);

        $this->expectException(LocalizedException::class);

        (new QuoteOnlySubmitGuard($this->mode, new QuotedCart(new QuotedLine(new Json()))))->beforeSubmit($this->management, $this->cart);
    }

    public function testAnOrdinaryCartInANormalStoreIsPlaced(): void
    {
        // Also the exempt case: for an admin or integration caller QuoteOnlyMode answers
        // false to both questions, so TackQuote's own conversion is placed.
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('cartHasQuoteOnlyProduct')->willReturn(false);

        (new QuoteOnlySubmitGuard($this->mode, new QuotedCart(new QuotedLine(new Json()))))->beforeSubmit($this->management, $this->cart);

        self::assertTrue(true);
    }

    public function testAnIntactAcceptedQuoteIsTheOneOrderAQuoteOnlyStorePlaces(): void
    {
        // Lane MG-QUOTE-CHECKOUT-LINK. Only an INTACT quoted cart: one that merely contains a
        // quoted line is refused first by Plugin\Quote\QuoteCheckoutSubmitGuard.
        $this->mode->method('isActive')->willReturn(true);
        $quotedCart = $this->createMock(QuotedCart::class);
        $quotedCart->method('isIntact')->with($this->cart)->willReturn(true);

        (new QuoteOnlySubmitGuard($this->mode, $quotedCart))->beforeSubmit($this->management, $this->cart);

        self::assertTrue(true);
    }

    public function testABrokenQuotedCartGetsNoExemption(): void
    {
        $this->mode->method('isActive')->willReturn(true);
        $quotedCart = $this->createMock(QuotedCart::class);
        $quotedCart->method('isIntact')->willReturn(false);

        $this->expectException(LocalizedException::class);

        (new QuoteOnlySubmitGuard($this->mode, $quotedCart))->beforeSubmit($this->management, $this->cart);
    }
}
