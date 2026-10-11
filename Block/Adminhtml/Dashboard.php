<?php
/**
 * Backing block for the TackQuote admin dashboard.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Config\Source\QuoteOnlyScope;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;

class Dashboard extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QuoteOnlyScope
     */
    private $quoteOnlyScope;

    /**
     * @var GroupRulesConfig
     */
    private $groupRules;

    /**
     * @param Context $context
     * @param Config $config
     * @param QuoteOnlyScope $quoteOnlyScope
     * @param GroupRulesConfig $groupRules
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        QuoteOnlyScope $quoteOnlyScope,
        GroupRulesConfig $groupRules,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->quoteOnlyScope = $quoteOnlyScope;
        $this->groupRules = $groupRules;
    }

    /**
     * Whether TackQuote is switched on for the current scope.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Whether the storefront button will actually render. Mirrors
     * Config::isButtonEnabled() so the dashboard can explain a missing button rather
     * than leaving an admin to wonder why the storefront looks unchanged.
     *
     * @return bool
     */
    public function isButtonEnabled(): bool
    {
        return $this->config->isButtonEnabled();
    }

    /**
     * Whether an API key has been saved.
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    /**
     * The configured TackQuote API base URL, for display.
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->config->getApiBaseUrl();
    }

    /**
     * The storefront quote button's current label, for display.
     *
     * @return string
     */
    public function getButtonLabel(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * The B2B display and B2B checkout switches as the merchant set them, for the "B2B features"
     * card.
     *
     * Raw switch state (Config::isB2bSwitchOn), like the WooCommerce Overview's card: the
     * merchant sees what they turned on even while the module cannot act on it yet, and
     * isB2bLive() says whether it can.
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public function getB2bSwitches(): array
    {
        $labels = [
            Config::B2B_ACCOUNT_PRICE => (string) __('Account price'),
            Config::B2B_QUANTITY_BREAKS => (string) __('Volume pricing table'),
            Config::B2B_ORDER_LIMITS => (string) __('Order limits notice'),
            Config::B2B_BUYER_GROUP => (string) __('Buyer group badge'),
        ];

        $out = [];
        foreach (Config::B2B_FEATURES as $feature) {
            $out[] = [
                'label' => $labels[$feature],
                'on' => $this->config->isB2bSwitchOn($feature),
            ];
        }

        // B2B checkout enforcement (group `b2b_checkout`), raw switch state like the rows above.
        $out[] = [
            'label' => (string) __('Account prices charged in cart'),
            'on' => $this->config->isEnforcePricesSwitchOn(),
        ];
        $out[] = [
            'label' => (string) __('Order limits enforced'),
            'on' => $this->config->isEnforceOrderLimitsSwitchOn(),
        ];

        return $out;
    }

    /**
     * The quote-only rows of the "B2B features" card, as the merchant set them.
     *
     * Raw settings, like getB2bSwitches(): the store-wide switch, who it applies to (shown only
     * while it is on), and "Price on request". The per-product switch lives on each product
     * (attribute `tackquote_quote_only`), so the card names it instead of counting products.
     *
     * @return array<int, array{label: string, on: bool|null, value?: string}>
     */
    public function getQuoteOnlyRows(): array
    {
        $enabled = $this->config->isQuoteOnlyEnabled();
        $rows = [
            ['label' => (string) __('Quote-only store'), 'on' => $enabled],
        ];

        if ($enabled) {
            $scope = $this->config->getQuoteOnlyScope();
            $label = $scope;
            foreach ($this->quoteOnlyScope->toOptionArray() as $option) {
                if ($option['value'] === $scope) {
                    $label = (string) $option['label'];
                }
            }
            $rows[] = ['label' => (string) __('Applies to'), 'on' => null, 'value' => $label];
        }

        $rows[] = ['label' => (string) __('Price on request'), 'on' => $this->config->isQuoteOnlyHidePrices()];
        $rows[] = [
            'label' => (string) __('Quote only, per product'),
            'on' => null,
            'value' => (string) __('Set "Quote only (TackQuote)" on a product'),
        ];

        return $rows;
    }

    /**
     * The buyer-group rule rows of the "B2B features" card, as the merchant set them (raw
     * switches, like getB2bSwitches()).
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public function getGroupRuleRows(): array
    {
        $rows = [
            GroupRulesConfig::CATALOG_ENABLED => (string) __('Hidden categories by buyer group'),
            GroupRulesConfig::RESTRICTIONS_ENABLED => (string) __('Payment and shipping methods by buyer group'),
            GroupRulesConfig::DISCOUNTS_ENABLED => (string) __('Shipping discounts by buyer group'),
            GroupRulesConfig::TAX_EXEMPT_ENABLED => (string) __('Tax-exempt buyers'),
            GroupRulesConfig::MIRROR_ENABLED => (string) __('Customer group from buyer group'),
        ];

        $out = [];
        foreach ($rows as $field => $label) {
            $out[] = ['label' => $label, 'on' => $this->groupRules->isSwitchOn($field)];
        }

        return $out;
    }

    /**
     * Whether switched-on B2B features can reach the storefront (enabled + API key).
     *
     * @return bool
     */
    public function isB2bLive(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Admin AJAX URL behind the "Test connection" button.
     *
     * @return string
     */
    public function getTestConnectionUrl(): string
    {
        return $this->getUrl('tackquote/connection/test');
    }

    /**
     * Deep link to this module's own section of Stores > Configuration.
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'tackquote_quotes']);
    }

    /**
     * Setup checklist explaining whether the storefront button will show.
     *
     * The single most useful diagnostic on this page: exactly why the storefront button
     * is or is not showing, in the order an admin would need to fix them.
     *
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public function getChecklist(): array
    {
        $hasKey = $this->hasApiKey();

        return [
            [
                'label' => (string) __('TackQuote is enabled'),
                'ok' => $this->config->isEnabled(),
                'hint' => (string) __('Turn on "Enable TackQuote" in Settings.'),
            ],
            [
                'label' => (string) __('API key is set'),
                'ok' => $hasKey,
                'hint' => (string) __(
                    'Press Connect with TackQuote above, or create a key in TackQuote under '
                    . 'Settings > Developer > API Keys with the quotes:write scope and paste it in Settings.'
                ),
            ],
            [
                'label' => (string) __('Quote button is turned on'),
                'ok' => $this->isButtonEnabled(),
                'hint' => (string) __(
                    'Enable "Show quote button on product pages" in Settings. It also needs an API key.'
                ),
            ],
        ];
    }
}
