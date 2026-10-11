<?php
/**
 * Data for the TackQuote admin Overview (TackQuote > Overview).
 *
 * One tab per settings area, in the WooCommerce plugin's tab order (Connection, Storefront,
 * B2B pricing, Buyer groups, Forms, Orders, Help). Each tab lists the switches of its area as
 * the merchant set them, and every row links straight to its group in Stores > Configuration.
 *
 * A view model rather than Block methods, as the Magento 2.4 frontend guidelines recommend:
 * the page block is a plain Magento\Backend\Block\Template and this class holds the data
 * (view/adminhtml/layout/tackquote_dashboard_index.xml).
 *
 * Values are read at the default scope, which is what the configuration page shows first; a
 * website can override them, and the page says so.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Config\Source\QuoteOnlyScope;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\Model\Styling\StyleRules;
use TackQuote\Quotes\ViewModel\Styling;

class Overview implements ArgumentInterface
{
    /** The configuration section every Overview link opens. */
    public const SECTION = 'tackquote_quotes';

    /** The ACL resource of that section (etc/acl.xml); without it the links are not shown. */
    private const CONFIG_RESOURCE = 'TackQuote_Quotes::config';

    /** Public setup guide and support, the same pages the WooCommerce plugin links to. */
    public const DOCS_URL = 'https://tackquote.com/docs/integrations/magento';
    public const SUPPORT_URL = 'https://tackquote.com/contact';

    /** Raw switch paths read for the Storefront, Forms and Orders tabs. */
    private const PATH_SHOW_BUTTON = 'tackquote_quotes/storefront/show_button';
    private const PATH_SHOW_ADD_TO_QUOTE = 'tackquote_quotes/storefront/show_add_to_quote';
    private const PATH_SHOW_ON_LISTING = 'tackquote_quotes/storefront/show_on_listing';
    private const PATH_QUOTE_PAGE = 'tackquote_quotes/quote_request/enable_quote_page';
    private const PATH_CART_BUTTON = 'tackquote_quotes/quote_request/enable_cart_button';
    private const PATH_TARGET_PRICE = 'tackquote_quotes/quote_request/enable_target_price';
    private const PATH_ATTACHMENTS = 'tackquote_quotes/quote_request/enable_attachments';
    private const PATH_WHOLESALE_TAB = 'tackquote_quotes/forms/enable_wholesale_tab';
    private const PATH_NET_TERMS_TAB = 'tackquote_quotes/forms/enable_net_terms_tab';
    private const PATH_NET_TERMS_ACTIVE = 'payment/tackquote_net_terms/active';
    private const PATH_QUOTE_CHECKOUT = 'tackquote_quotes/quote_checkout/enabled';

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
     * @var Styling
     */
    private $styling;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @param Config $config
     * @param QuoteOnlyScope $quoteOnlyScope
     * @param GroupRulesConfig $groupRules
     * @param Styling $styling
     * @param ModuleListInterface $moduleList
     * @param ScopeConfigInterface $scopeConfig
     * @param AuthorizationInterface $authorization
     * @param UrlInterface $url
     */
    public function __construct(
        Config $config,
        QuoteOnlyScope $quoteOnlyScope,
        GroupRulesConfig $groupRules,
        Styling $styling,
        ModuleListInterface $moduleList,
        ScopeConfigInterface $scopeConfig,
        AuthorizationInterface $authorization,
        UrlInterface $url
    ) {
        $this->config = $config;
        $this->quoteOnlyScope = $quoteOnlyScope;
        $this->groupRules = $groupRules;
        $this->styling = $styling;
        $this->moduleList = $moduleList;
        $this->scopeConfig = $scopeConfig;
        $this->authorization = $authorization;
        $this->url = $url;
    }

    /**
     * The Overview's tabs in reading order: panel id => tab label.
     *
     * @return array<string, string>
     */
    public function getTabs(): array
    {
        return [
            'tq-panel-connection' => (string) __('Connection'),
            'tq-panel-storefront' => (string) __('Storefront'),
            'tq-panel-pricing' => (string) __('B2B pricing'),
            'tq-panel-groups' => (string) __('Buyer groups'),
            'tq-panel-forms' => (string) __('Forms'),
            'tq-panel-orders' => (string) __('Orders'),
            'tq-panel-help' => (string) __('Help'),
        ];
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
     * Whether switched-on B2B features can reach the storefront (enabled + API key).
     *
     * @return bool
     */
    public function isB2bLive(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Setup checklist explaining whether the storefront button will show, in the order an
     * admin would need to fix them. The cron step is added by the template from CronStatus,
     * which says whether Magento cron has run this module's jobs yet.
     *
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public function getChecklist(): array
    {
        return [
            [
                'label' => (string) __('TackQuote is enabled'),
                'ok' => $this->config->isEnabled(),
                'hint' => (string) __('Turn on "Enable TackQuote" in Settings.'),
            ],
            [
                'label' => (string) __('API key is set'),
                'ok' => $this->hasApiKey(),
                'hint' => (string) __(
                    'Press Connect with TackQuote above, or create a key in TackQuote under '
                    . 'Settings > Developer > API Keys with the quotes:write scope and paste it in Settings.'
                ),
            ],
            [
                'label' => (string) __('Quote button is turned on'),
                'ok' => $this->config->isButtonEnabled(),
                'hint' => (string) __(
                    'Enable "Show quote button on product pages" in Settings. It also needs an API key.'
                ),
            ],
        ];
    }

    /**
     * Storefront tab, "Quote buttons and quote page" section (raw switches).
     *
     * @return array<int, array{label: string, on: bool, group: string}>
     */
    public function getStorefrontRows(): array
    {
        return [
            $this->row(__('"Request a Quote" on product pages'), self::PATH_SHOW_BUTTON, 'storefront'),
            $this->row(__('"Add to Quote" and the quote list'), self::PATH_SHOW_ADD_TO_QUOTE, 'storefront'),
            $this->row(__('"Add to Quote" on category and search pages'), self::PATH_SHOW_ON_LISTING, 'storefront'),
            $this->row(__('Quote page'), self::PATH_QUOTE_PAGE, 'quote_request'),
            $this->row(__('Quote button on the cart page'), self::PATH_CART_BUTTON, 'quote_request'),
            $this->row(__('Target price per product'), self::PATH_TARGET_PRICE, 'quote_request'),
            $this->row(__('Attachments'), self::PATH_ATTACHMENTS, 'quote_request'),
        ];
    }

    /**
     * Storefront tab, "Quote-only store" section, as the merchant set it.
     *
     * The store-wide switch, who it applies to (shown only while it is on), and "Price on
     * request". The per-product switch lives on each product (attribute `tackquote_quote_only`),
     * so the row names it instead of counting products.
     *
     * @return array<int, array{label: string, on: bool|null, value?: string, group: string}>
     */
    public function getQuoteOnlyRows(): array
    {
        $enabled = $this->config->isQuoteOnlyEnabled();
        $rows = [
            ['label' => (string) __('Quote-only store'), 'on' => $enabled, 'group' => 'quote_only'],
        ];

        if ($enabled) {
            $scope = $this->config->getQuoteOnlyScope();
            $label = $scope;
            foreach ($this->quoteOnlyScope->toOptionArray() as $option) {
                if ($option['value'] === $scope) {
                    $label = (string) $option['label'];
                }
            }
            $rows[] = ['label' => (string) __('Applies to'), 'on' => null, 'value' => $label, 'group' => 'quote_only'];
        }

        $rows[] = [
            'label' => (string) __('Price on request'),
            'on' => $this->config->isQuoteOnlyHidePrices(),
            'group' => 'quote_only',
        ];
        $rows[] = [
            'label' => (string) __('Quote only, per product'),
            'on' => null,
            'value' => (string) __('Set "Quote only (TackQuote)" on a product'),
            'group' => '',
        ];

        return $rows;
    }

    /**
     * Storefront tab, "Styling" section. `swatch` is a validated hex colour or ''.
     *
     * @return array<int, array{label: string, on: null, value: string, swatch: string, group: string}>
     */
    public function getStylingRows(): array
    {
        $accent = $this->styling->getAccentColor();
        $position = $this->styling->getLauncherPosition() === StyleRules::POSITION_LEFT
            ? (string) __('Bottom left')
            : (string) __('Bottom right');
        $size = $this->styling->getLauncherSize() === StyleRules::SIZE_COMPACT
            ? (string) __('Compact')
            : (string) __('Regular');

        return [
            [
                'label' => (string) __('Accent color'),
                'on' => null,
                'value' => $accent !== '' ? $accent : (string) __('Your theme\'s colors'),
                'swatch' => $accent,
                'group' => 'styling',
            ],
            [
                'label' => (string) __('Button style'),
                'on' => null,
                'value' => $this->styling->getButtonStyle() === StyleRules::BUTTON_OUTLINED
                    ? (string) __('Outlined')
                    : (string) __('Filled'),
                'swatch' => '',
                'group' => 'styling',
            ],
            [
                'label' => (string) __('Quote list launcher'),
                'on' => null,
                'value' => (string) __(
                    '%1, %2 px from the edges, %3, "%4"',
                    $position,
                    $this->styling->getLauncherOffset(),
                    $size,
                    $this->styling->getLauncherLabel()
                ),
                'swatch' => '',
                'group' => 'styling',
            ],
            [
                'label' => (string) __('Custom CSS'),
                'on' => null,
                'value' => $this->styling->hasCustomCss() ? (string) __('Added') : (string) __('None'),
                'swatch' => '',
                'group' => 'styling',
            ],
        ];
    }

    /**
     * B2B pricing tab: the B2B display and B2B checkout switches as the merchant set them.
     *
     * Raw switch state (Config::isB2bSwitchOn), like the WooCommerce Overview: the merchant
     * sees what they turned on even while the module cannot act on it yet, and isB2bLive()
     * says whether it can.
     *
     * @return array<int, array{label: string, on: bool, group: string}>
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
                'group' => 'b2b',
            ];
        }

        $out[] = [
            'label' => (string) __('Account prices charged in cart'),
            'on' => $this->config->isEnforcePricesSwitchOn(),
            'group' => 'b2b_checkout',
        ];
        $out[] = [
            'label' => (string) __('Order limits enforced'),
            'on' => $this->config->isEnforceOrderLimitsSwitchOn(),
            'group' => 'b2b_checkout',
        ];

        return $out;
    }

    /**
     * Buyer groups tab: the buyer-group rule switches, as the merchant set them.
     *
     * @return array<int, array{label: string, on: bool, group: string}>
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
            $out[] = ['label' => $label, 'on' => $this->groupRules->isSwitchOn($field), 'group' => 'b2b_groups'];
        }

        return $out;
    }

    /**
     * Forms tab: the My Account tabs and the net terms payment method (raw switches).
     *
     * @return array<int, array{label: string, on: bool, group: string}>
     */
    public function getFormsRows(): array
    {
        return [
            $this->row(__('"Wholesale account" tab'), self::PATH_WHOLESALE_TAB, 'forms'),
            $this->row(__('"Net terms" tab'), self::PATH_NET_TERMS_TAB, 'forms'),
            $this->row(__('Pay on net terms at checkout'), self::PATH_NET_TERMS_ACTIVE, 'net_terms'),
        ];
    }

    /**
     * Orders tab: accepted quote checkout (group `quote_checkout`), raw switch.
     *
     * @return array<int, array{label: string, on: bool, group: string}>
     */
    public function getOrdersRows(): array
    {
        return [
            $this->row(__('Open accepted quotes in the checkout'), self::PATH_QUOTE_CHECKOUT, 'quote_checkout'),
        ];
    }

    /**
     * How many of the given rows are on, for a section's "N of M on" count.
     *
     * @param array<int, array{on: bool|null}> $rows
     * @return int
     */
    public function countOn(array $rows): int
    {
        $on = 0;
        foreach ($rows as $row) {
            if (($row['on'] ?? null) === true) {
                $on++;
            }
        }

        return $on;
    }

    /**
     * How many of the given rows are switches (on or off), for the "of M" half of the count.
     *
     * @param array<int, array{on: bool|null}> $rows
     * @return int
     */
    public function countSwitches(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (is_bool($row['on'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * This module's version (etc/module.xml), for the Help tab.
     *
     * @return string
     */
    public function getModuleVersion(): string
    {
        $module = $this->moduleList->getOne('TackQuote_Quotes');

        return is_array($module) && isset($module['setup_version']) ? (string) $module['setup_version'] : '';
    }

    /**
     * Whether the signed-in admin may open Stores > Configuration > TackQuote. Without it the
     * Overview still shows every setting, but offers no links that would end on "access denied".
     *
     * @return bool
     */
    public function canConfigure(): bool
    {
        return $this->authorization->isAllowed(self::CONFIG_RESOURCE);
    }

    /**
     * Admin AJAX URL behind the "Test connection" button.
     *
     * @return string
     */
    public function getTestConnectionUrl(): string
    {
        return $this->url->getUrl('tackquote/connection/test');
    }

    /**
     * Deep link to this module's own section of Stores > Configuration.
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->url->getUrl('adminhtml/system_config/edit', ['section' => self::SECTION]);
    }

    /**
     * Deep link to one group of the TackQuote configuration section, opened.
     *
     * Magento's configuration page expands the group named in the URL fragment
     * `#<section>_<group>-link` (Magento_Config view/adminhtml/templates/system/config/
     * edit.phtml, handleHash(); the ids come from Block\System\Config\Form\Fieldset::
     * _getHeaderHtml()), checked against Magento 2.4.8-p5.
     *
     * @param string $group A group id of etc/adminhtml/system.xml.
     * @return string
     */
    public function getGroupUrl(string $group): string
    {
        return $this->url->getUrl(
            'adminhtml/system_config/edit',
            ['section' => self::SECTION, '_fragment' => self::SECTION . '_' . $group . '-link']
        );
    }

    /**
     * Whether the value is a hex colour the template may paint as a swatch.
     *
     * @param string $value
     * @return bool
     */
    public function isSwatch(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/', $value) === 1;
    }

    /**
     * One switch row read from a raw Yes/No path at the default scope.
     *
     * @param \Magento\Framework\Phrase|string $label
     * @param string $path
     * @param string $group
     * @return array{label: string, on: bool, group: string}
     */
    private function row($label, string $path, string $group): array
    {
        return [
            'label' => (string) $label,
            'on' => $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE),
            'group' => $group,
        ];
    }
}
