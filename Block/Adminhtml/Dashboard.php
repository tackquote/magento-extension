<?php
/**
 * Backing block for the TackQuote admin Overview (TackQuote > Overview).
 *
 * Laid out like the WooCommerce plugin's Overview tab (class-tack-settings.php,
 * render_overview() and its card_* methods): one card per settings area, in the plugin's tab
 * order (Connection, Storefront, B2B pricing, Buyer groups, Forms, Orders, Help), each showing
 * what is switched on and linking straight to its group in Stores > Configuration.
 *
 * Values are read at the default scope (the admin store), which is what the configuration
 * page shows first; a website can override them, and the page says so.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Config\Source\QuoteOnlyScope;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\Model\Styling\StyleRules;
use TackQuote\Quotes\ViewModel\Styling;

class Dashboard extends Template
{
    /** The configuration section every Overview link opens. */
    public const SECTION = 'tackquote_quotes';

    /** The ACL resource of that section (etc/acl.xml); without it the links are not shown. */
    private const CONFIG_RESOURCE = 'TackQuote_Quotes::config';

    /** Public setup guide and support, the same pages the WooCommerce plugin links to. */
    public const DOCS_URL = 'https://tackquote.com/docs/integrations/magento';
    public const SUPPORT_URL = 'https://tackquote.com/contact';

    /** Raw switch paths read for the Storefront and Forms cards. */
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
     * @param Context $context
     * @param Config $config
     * @param QuoteOnlyScope $quoteOnlyScope
     * @param GroupRulesConfig $groupRules
     * @param Styling $styling
     * @param ModuleListInterface $moduleList
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        QuoteOnlyScope $quoteOnlyScope,
        GroupRulesConfig $groupRules,
        Styling $styling,
        ModuleListInterface $moduleList,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->quoteOnlyScope = $quoteOnlyScope;
        $this->groupRules = $groupRules;
        $this->styling = $styling;
        $this->moduleList = $moduleList;
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

    /**
     * The Overview's cards in reading order: anchor id => card title. Drawn as the tab row at
     * the top of the page; the WooCommerce plugin's tabs, with Orders for its Order sync.
     *
     * @return array<string, string>
     */
    public function getTabs(): array
    {
        return [
            'tq-card-connection' => (string) __('Connection'),
            'tq-card-storefront' => (string) __('Storefront'),
            'tq-card-pricing' => (string) __('B2B pricing'),
            'tq-card-groups' => (string) __('Buyer groups'),
            'tq-card-forms' => (string) __('Forms'),
            'tq-card-orders' => (string) __('Orders'),
            'tq-card-help' => (string) __('Help'),
        ];
    }

    /**
     * Whether the signed-in admin may open Stores > Configuration > TackQuote. Without it the
     * Overview still shows every setting, but offers no links that would end on "access denied".
     *
     * @return bool
     */
    public function canConfigure(): bool
    {
        return $this->_authorization->isAllowed(self::CONFIG_RESOURCE);
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
        return $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => self::SECTION, '_fragment' => self::SECTION . '_' . $group . '-link']
        );
    }

    /**
     * The Storefront card: the quote buttons and quote list as the merchant set them.
     *
     * Raw switch state, like getB2bSwitches(): what the merchant turned on, even while the
     * module cannot show it yet (isB2bLive() and the checklist say why).
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public function getStorefrontRows(): array
    {
        return [
            ['label' => (string) __('"Request a Quote" on product pages'), 'on' => $this->flag(self::PATH_SHOW_BUTTON)],
            ['label' => (string) __('"Add to Quote" and the quote list'), 'on' => $this->flag(self::PATH_SHOW_ADD_TO_QUOTE)],
            ['label' => (string) __('"Add to Quote" on category and search pages'), 'on' => $this->flag(self::PATH_SHOW_ON_LISTING)],
            ['label' => (string) __('Quote page'), 'on' => $this->flag(self::PATH_QUOTE_PAGE)],
            ['label' => (string) __('Quote button on the cart page'), 'on' => $this->flag(self::PATH_CART_BUTTON)],
            ['label' => (string) __('Target price per product'), 'on' => $this->flag(self::PATH_TARGET_PRICE)],
            ['label' => (string) __('Attachments'), 'on' => $this->flag(self::PATH_ATTACHMENTS)],
        ];
    }

    /**
     * The Styling rows of the Storefront card. `swatch` is a validated hex colour or ''.
     *
     * @return array<int, array{label: string, value: string, swatch: string}>
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
                'value' => $accent !== '' ? $accent : (string) __('Your theme\'s colors'),
                'swatch' => $accent,
            ],
            [
                'label' => (string) __('Button style'),
                'value' => $this->styling->getButtonStyle() === StyleRules::BUTTON_OUTLINED
                    ? (string) __('Outlined')
                    : (string) __('Filled'),
                'swatch' => '',
            ],
            [
                'label' => (string) __('Quote list launcher'),
                'value' => (string) __(
                    '%1, %2 px from the edges, %3, "%4"',
                    $position,
                    $this->styling->getLauncherOffset(),
                    $size,
                    $this->styling->getLauncherLabel()
                ),
                'swatch' => '',
            ],
            [
                'label' => (string) __('Custom CSS'),
                'value' => $this->styling->hasCustomCss() ? (string) __('Added') : (string) __('None'),
                'swatch' => '',
            ],
        ];
    }

    /**
     * The Forms card: the My Account tabs and the net terms payment method (raw switches).
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public function getFormsRows(): array
    {
        return [
            ['label' => (string) __('"Wholesale account" tab'), 'on' => $this->flag(self::PATH_WHOLESALE_TAB)],
            ['label' => (string) __('"Net terms" tab'), 'on' => $this->flag(self::PATH_NET_TERMS_TAB)],
            ['label' => (string) __('Pay on net terms at checkout'), 'on' => $this->flag(self::PATH_NET_TERMS_ACTIVE)],
        ];
    }

    /**
     * The Orders card: accepted quote checkout (group `quote_checkout`), raw switch.
     *
     * @return array<int, array{label: string, on: bool}>
     */
    public function getOrdersRows(): array
    {
        return [
            ['label' => (string) __('Open accepted quotes in the checkout'), 'on' => $this->flag(self::PATH_QUOTE_CHECKOUT)],
        ];
    }

    /**
     * How many of the given rows are on, for a card's "2 of 7 on" pill.
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
     * This module's version (etc/module.xml), for the Help card.
     *
     * @return string
     */
    public function getModuleVersion(): string
    {
        $module = $this->moduleList->getOne('TackQuote_Quotes');

        return is_array($module) && isset($module['setup_version']) ? (string) $module['setup_version'] : '';
    }

    /**
     * A raw Yes/No switch at the default scope.
     *
     * @param string $path
     * @return bool
     */
    private function flag(string $path): bool
    {
        return $this->_scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
}
