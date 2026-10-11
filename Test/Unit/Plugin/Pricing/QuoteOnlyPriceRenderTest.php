<?php
/**
 * "Price on request" and the per-product "Available on quote" label.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Pricing;

use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\Render;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Pricing\QuoteOnlyPriceRender;

/**
 * @covers \TackQuote\Quotes\Plugin\Pricing\QuoteOnlyPriceRender
 */
class QuoteOnlyPriceRenderTest extends TestCase
{
    private const PRICE_HTML = '<div class="price-box"><span class="price">$12.00</span></div>';

    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var Render&MockObject */
    private $render;

    /** @var Product&MockObject */
    private $product;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        // No method is stubbed: the plugin never calls the subject, and overriding every
        // AbstractBlock method would reflect formatDate()'s \IntlDateFormatter default, which
        // needs ext-intl in the test runtime.
        $this->render = $this->getMockBuilder(Render::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $this->product = $this->createMock(Product::class);
    }

    private function plugin(): QuoteOnlyPriceRender
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES);
        });

        return new QuoteOnlyPriceRender($this->mode, $escaper);
    }

    private function render(string $priceCode, ?bool &$proceeded = null): string
    {
        $proceeded = false;

        return (string) $this->plugin()->aroundRender(
            $this->render,
            static function () use (&$proceeded) {
                $proceeded = true;

                return self::PRICE_HTML;
            },
            $priceCode,
            $this->product,
            []
        );
    }

    public function testTheFinalPriceBecomesPriceOnRequestWhenPricesAreHidden(): void
    {
        $this->mode->method('shouldHidePrices')->willReturn(true);

        $html = $this->render('final_price', $proceeded);

        self::assertFalse($proceeded, 'the real price must not even be rendered');
        self::assertStringContainsString('Price on request', $html);
        self::assertStringNotContainsString('$12.00', $html);
        self::assertStringNotContainsString(
            'price-box',
            $html,
            'Magento\'s price-box JavaScript would try to update a box with no price in it'
        );
    }

    public function testEveryOtherPriceCodeRendersNothingWhenPricesAreHidden(): void
    {
        // A tier-price ladder beside "Price on request" would put the numbers back.
        $this->mode->method('shouldHidePrices')->willReturn(true);

        self::assertSame('', $this->render('tier_price', $proceeded));
        self::assertFalse($proceeded);
    }

    public function testAQuoteOnlyProductKeepsItsPriceAndGainsTheLabel(): void
    {
        $this->mode->method('shouldHidePrices')->willReturn(false);
        $this->mode->method('isProductQuoteOnly')->with($this->product)->willReturn(true);

        $html = $this->render('final_price');

        self::assertStringStartsWith(self::PRICE_HTML, $html, 'WooCommerce parity: the price stays');
        self::assertStringContainsString('Available on quote', $html);
        self::assertStringContainsString(QuoteOnlyPriceRender::CLASS_AVAILABLE_ON_QUOTE, $html);
    }

    public function testTheLabelIsAddedOnceToTheFinalPriceNotToEveryPriceCode(): void
    {
        $this->mode->method('shouldHidePrices')->willReturn(false);
        $this->mode->method('isProductQuoteOnly')->willReturn(true);

        self::assertSame(self::PRICE_HTML, $this->render('tier_price'));
    }

    public function testAnOrdinaryProductIsRenderedUntouched(): void
    {
        $this->mode->method('shouldHidePrices')->willReturn(false);
        $this->mode->method('isProductQuoteOnly')->willReturn(false);

        self::assertSame(self::PRICE_HTML, $this->render('final_price', $proceeded));
        self::assertTrue($proceeded);
    }
}
