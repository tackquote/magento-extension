<?php
/**
 * Settings of the "Quote page, cart button and attachments" group
 * (Stores > Configuration > TackQuote > TackQuote Settings).
 *
 * A class of its own rather than more methods on Model\Config, so this group's readers sit in
 * one place. Every switch is OFF until a merchant turns it on (etc/config.xml): a module
 * upgrade must not change what shoppers see.
 *
 * Every switch also requires Config::isConfigured() (module enabled AND an API key), for the
 * reason every other storefront control does: without a key nothing can be submitted, so the
 * control would be a dead end.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class QuoteRequestConfig
{
    private const PREFIX = 'tackquote_quotes/quote_request/';

    private const DEFAULT_PAGE_LINK_LABEL = 'Open the quote page';
    private const DEFAULT_CART_BUTTON_LABEL = 'Request a quote for your cart';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $config
     */
    public function __construct(ScopeConfigInterface $scopeConfig, Config $config)
    {
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
    }

    /**
     * The full quote page (/tackquote/quote) and its link from the quote list.
     *
     * Needs the quote list itself: the page shows that list.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isQuotePageEnabled(?int $storeId = null): bool
    {
        return $this->config->isAddToQuoteEnabled($storeId) && $this->flag('enable_quote_page', $storeId);
    }

    /**
     * Label of the quote-page link in the quote list.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getQuotePageLinkLabel(?int $storeId = null): string
    {
        return $this->text('quote_page_link_label', self::DEFAULT_PAGE_LINK_LABEL, $storeId);
    }

    /**
     * An optional "Target price" per line in the quote form and on the quote page.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTargetPriceEnabled(?int $storeId = null): bool
    {
        return $this->config->isConfigured($storeId) && $this->flag('enable_target_price', $storeId);
    }

    /**
     * "Request a quote for your cart" on the cart page, in normal (not quote-only) mode.
     *
     * Needs the quote list: the button fills it from the cart.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCartButtonEnabled(?int $storeId = null): bool
    {
        return $this->config->isAddToQuoteEnabled($storeId) && $this->flag('enable_cart_button', $storeId);
    }

    /**
     * Label of the cart-page button.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCartButtonLabel(?int $storeId = null): string
    {
        return $this->text('cart_button_label', self::DEFAULT_CART_BUTTON_LABEL, $storeId);
    }

    /**
     * The merchant's attachments switch.
     *
     * Whether the control renders also needs the server's `attachments` capability
     * (Model\ServerCapabilities); see Block\QuoteList.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAttachmentsSwitchOn(?int $storeId = null): bool
    {
        return $this->config->isConfigured($storeId) && $this->flag('enable_attachments', $storeId);
    }

    /**
     * One Yes/No setting of this group.
     *
     * @param string $field
     * @param int|null $storeId
     * @return bool
     */
    private function flag(string $field, ?int $storeId): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * One text setting of this group, or its default when blank.
     *
     * @param string $field
     * @param string $default
     * @param int|null $storeId
     * @return string
     */
    private function text(string $field, string $default, ?int $storeId): string
    {
        $value = trim(
            (string) $this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId)
        );

        return $value !== '' ? $value : $default;
    }
}
