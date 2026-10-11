<?php
/**
 * Quote-only mode — "Price on request", and the "Available on quote" label.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY Magento\Framework\Pricing\Render::render()
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * It is the one method every catalogue price box is drawn through (Magento 2.4.8):
 *   - the product page's price blocks are Magento\Catalog\Pricing\Render, whose _toHtml()
 *     returns `$priceRender->render($this->getPriceTypeCode(), $product, $arguments)`
 *     (Catalog/Pricing/Render.php:60) — final price and tier prices alike;
 *   - category, search, related, up-sell and widget tiles go through
 *     ListProduct::getProductPrice() -> `$priceRender->render(FinalPrice::PRICE_CODE, …)`
 *     (Catalog/Block/Product/ListProduct.php:419).
 * render($priceCode, SaleableInterface $saleableItem, array $arguments = []) is public on a
 * non-final class (Framework/Pricing/Render.php:85), so an `around` plugin may replace its
 * result (developer.adobe.com/commerce/php/development/components/plugins). No template is
 * overridden, so no theme's price templates are clobbered.
 *
 * WHAT IT DOES
 *   - "Price on request" (QuoteOnlyMode::shouldHidePrices(): the store-wide mode applies to
 *     this visitor AND `quote_only/hide_prices` is on): the final-price box becomes the
 *     translatable text; every other price code (tier prices, MSRP, …) renders nothing, so no
 *     number is left beside the text. The replacement deliberately does NOT carry the
 *     `price-box` class or `data-role="priceBox"`: Magento's price-box JavaScript would
 *     otherwise try to update a box that has no price in it.
 *   - Otherwise, a product quote-only by its OWN flag keeps its price and gets "Available on
 *     quote" after the final-price box (the WooCommerce plugin's per-product label). The
 *     label is also the hook the stylesheet uses to hide that tile's Add to Cart button.
 *
 * WHAT IT DOES NOT TOUCH. The cart, mini-cart and checkout totals (they use their own item
 * renderers), the admin (this plugin is declared in etc/frontend/di.xml only), the TackQuote
 * quote request (Model\ProductQuoteResolver still sends Magento's price), and machine-readable
 * prices — the configurable product JSON, custom-option price labels, the Open Graph
 * `product:price:amount` meta tag, REST and GraphQL. README "Known limitations" lists them:
 * this is a display setting, as it is in WooCommerce, not a secret.
 *
 * FULL-PAGE CACHE. The decision reads only what QuoteOnlyMode reads, and every input of that
 * is in the page-cache vary data — customer group and signed-in state from Magento's own
 * context plugin, wholesale approval from Plugin\PageCache\WholesaleApprovalContext — so a
 * cached page with hidden prices is only ever served to visitors who would get hidden prices.
 * The per-product label depends only on the product, whose cache entries Magento tags and
 * cleans on product save.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Pricing;

use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\Render;
use Magento\Framework\Pricing\SaleableInterface;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyPriceRender
{
    /** CSS hook of the replacement text. */
    public const CLASS_PRICE_ON_REQUEST = 'tackquote-price-on-request';

    /** CSS hook of the per-product label (_module.less keys the tile rule off it). */
    public const CLASS_AVAILABLE_ON_QUOTE = 'tackquote-available-on-quote';

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param Escaper $escaper
     */
    public function __construct(QuoteOnlyMode $quoteOnlyMode, Escaper $escaper)
    {
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->escaper = $escaper;
    }

    /**
     * Render "Price on request" instead of the price where quote-only applies.
     *
     * @param Render $subject
     * @param callable $proceed
     * @param string $priceCode
     * @param SaleableInterface $saleableItem
     * @param array $arguments
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRender(
        Render $subject,
        callable $proceed,
        $priceCode,
        SaleableInterface $saleableItem,
        array $arguments = []
    ) {
        $isFinalPrice = (string) $priceCode === FinalPrice::PRICE_CODE;

        if ($this->quoteOnlyMode->shouldHidePrices()) {
            if (!$isFinalPrice) {
                return '';
            }

            return '<div class="' . self::CLASS_PRICE_ON_REQUEST . '"><span>'
                . $this->escaper->escapeHtml(__('Price on request'))
                . '</span></div>';
        }

        $html = $proceed($priceCode, $saleableItem, $arguments);

        if ($isFinalPrice && $this->quoteOnlyMode->isProductQuoteOnly($saleableItem)) {
            $html = (string) $html
                . '<div class="' . self::CLASS_AVAILABLE_ON_QUOTE . '"><span>'
                . $this->escaper->escapeHtml(__('Available on quote'))
                . '</span></div>';
        }

        return $html;
    }
}
