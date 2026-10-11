<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard
 */
class QuoteOnlyCartGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var Quote&MockObject */
    private $quote;

    /** @var Product&MockObject */
    private $product;

    /** @var QuotedCart */
    private $quotedCart;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->quote = $this->createMock(Quote::class);
        $this->product = $this->createMock(Product::class);
        $this->quotedCart = new QuotedCart(new QuotedLine(new Json()));
    }

    private function guard(): QuoteOnlyCartGuard
    {
        return new QuoteOnlyCartGuard($this->mode, $this->quotedCart);
    }

    public function testAnAcceptedQuoteBeingPutIntoTheCartIsTheOneExemption(): void
    {
        // Lane MG-QUOTE-CHECKOUT-LINK: Model\QuoteCheckout\CartBuilder sets the flag around its
        // own adds; a quote-only store must still let the accepted quote in.
        $this->mode->expects(self::never())->method('isActive');
        $this->mode->expects(self::never())->method('isProductQuoteOnly');

        $this->quotedCart->startBuilding();
        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
    }

    public function testOnceTheQuoteIsInTheCartTheStoreIsQuoteOnlyAgain(): void
    {
        $this->mode->method('isActive')->willReturn(true);
        $this->quotedCart->startBuilding();
        $this->quotedCart->stopBuilding();

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
    }

    public function testACraftedAddToCartIsREFUSEDWhenTheModeApplies(): void
    {
        // The headline claim. A `before` plugin that throws aborts the intercepted call, so
        // Magento\Quote\Model\Quote::addProduct() (module-quote/Model/Quote.php:1630) never
        // runs and nothing enters the cart — whether the request came from the storefront
        // form or from curl.
        $this->mode->method('isActive')->willReturn(true);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
    }

    public function testTheRefusalExplainsItselfToTheShopper(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        try {
            $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
            self::fail('the guard did not refuse');
        } catch (LocalizedException $e) {
            // LocalizedException specifically, because
            // Magento\Checkout\Controller\Cart\Add::execute() catches that type at
            // Add.php:170 and renders the message to the shopper. A generic exception
            // becomes a 500 page.
            $message = (string) $e->getMessage();

            self::assertNotSame('', $message, 'a silent refusal reads as a broken shop');
            self::assertStringContainsString(
                'quote',
                strtolower($message),
                'the message must tell the shopper what to do instead'
            );
        }
    }

    public function testANormalStoreIsUntouched(): void
    {
        $this->mode->method('isActive')->willReturn(false);

        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);

        // Reaching here without an exception IS the assertion: the plugin returned void and
        // core's addProduct() proceeds.
        self::assertTrue(true);
    }

    public function testTheGuardAsksTheModeExactlyOncePerAttempt(): void
    {
        // Cheap, but it pins the shape: the decision is delegated to QuoteOnlyMode rather
        // than re-derived here from config, which is what keeps enforcement and the CTA from
        // drifting apart.
        $this->mode->expects(self::once())->method('isActive')->willReturn(false);

        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
    }

    public function testAQuoteOnlyProductIsRefusedWhileTheStoreIsInNormalMode(): void
    {
        // Per-product: the store sells normally, this one product is quote-only.
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('isProductQuoteOnly')->with($this->product)->willReturn(true);
        $this->product->method('getName')->willReturn('Industrial pump');

        try {
            $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
            self::fail('a quote-only product went into the cart');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('Industrial pump', (string) $e->getMessage());
            self::assertStringContainsString('quote', strtolower((string) $e->getMessage()));
        }
    }

    public function testAnOrdinaryProductInANormalStoreIsNotRefused(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->expects(self::once())->method('isProductQuoteOnly')->willReturn(false);

        $this->guard()->beforeAddProduct($this->quote, $this->product, 1);
    }
}
