<?php
/**
 * Settings for the buyer-group rules (Stores > Configuration > TackQuote > TackQuote Settings >
 * Buyer group rules, group `b2b_groups`).
 *
 * Kept out of Model\Config on purpose: these are the rules' own settings, read only by
 * Model\Groups\*. Every switch defaults to 0 (etc/config.xml), as the WooCommerce 1.10/1.11
 * switches default to "no": each one changes what a shopper can see, pay with or be charged,
 * which is the merchant's decision, not a module upgrade's.
 *
 * Every `is*Active()` also requires Config::isConfigured() (module on, API key saved): with
 * no key nothing can be asked, so a rule would only ever see "unknown".
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class GroupRulesConfig
{
    private const PREFIX = 'tackquote_quotes/b2b_groups/';

    public const CATALOG_ENABLED = 'enable_catalog_visibility';
    public const CATALOG_MAP = 'catalog_visibility_map';
    public const CATALOG_HIDE_WHEN_UNKNOWN = 'hide_catalog_when_unknown';
    public const RESTRICTIONS_ENABLED = 'enable_restrictions';
    public const PAYMENT_MAP = 'payment_map';
    public const SHIPPING_MAP = 'shipping_map';
    public const RESTRICT_WHEN_UNKNOWN = 'restrict_when_unknown';
    public const DISCOUNTS_ENABLED = 'enable_shipping_discounts';
    public const DISCOUNT_MAP = 'shipping_discount_map';
    public const TAX_EXEMPT_ENABLED = 'enable_tax_exempt';
    public const TAX_EXEMPT_CLASS = 'tax_exempt_class';
    public const MIRROR_ENABLED = 'enable_group_mirror';
    public const MIRROR_MAP = 'group_mirror_map';
    public const CUSTOMER_GROUPS = 'customer_groups';

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
     * A switch as the merchant set it (raw, for the admin dashboard).
     *
     * @param string $field One of the *_ENABLED constants.
     * @param int|null $storeId
     * @return bool
     */
    public function isSwitchOn(string $field, ?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Hidden categories apply on this store: switched on, connected, and at least one rule.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCatalogVisibilityActive(?int $storeId = null): bool
    {
        return $this->isLive(self::CATALOG_ENABLED, $storeId) && $this->getCategoryMap($storeId) !== [];
    }

    /**
     * @param int|null $storeId
     * @return array<int, string[]>
     */
    public function getCategoryMap(?int $storeId = null): array
    {
        return GroupRules::parseCategoryMap($this->raw(self::CATALOG_MAP, $storeId));
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function hideCatalogWhenUnknown(?int $storeId = null): bool
    {
        return $this->isSwitchOn(self::CATALOG_HIDE_WHEN_UNKNOWN, $storeId);
    }

    /**
     * Payment and shipping restrictions apply on this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isRestrictionsActive(?int $storeId = null): bool
    {
        return $this->isLive(self::RESTRICTIONS_ENABLED, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return array<string, string[]>
     */
    public function getPaymentMap(?int $storeId = null): array
    {
        return GroupRules::parseMap($this->raw(self::PAYMENT_MAP, $storeId));
    }

    /**
     * @param int|null $storeId
     * @return array<string, string[]>
     */
    public function getShippingMap(?int $storeId = null): array
    {
        return GroupRules::parseMap($this->raw(self::SHIPPING_MAP, $storeId));
    }

    /**
     * The strict reading for an outage (WooCommerce's `tackquote_restrict_when_group_unknown`
     * filter, made a setting). Default No: an outage leaves restricted methods available.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function restrictWhenUnknown(?int $storeId = null): bool
    {
        return $this->isSwitchOn(self::RESTRICT_WHEN_UNKNOWN, $storeId);
    }

    /**
     * Shipping discounts per group apply on this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDiscountsActive(?int $storeId = null): bool
    {
        return $this->isLive(self::DISCOUNTS_ENABLED, $storeId) && $this->getDiscountMap($storeId) !== [];
    }

    /**
     * @param int|null $storeId
     * @return array<string, array{mode: string, percent: float, methods: string[]}>
     */
    public function getDiscountMap(?int $storeId = null): array
    {
        return GroupRules::parseDiscountMap($this->raw(self::DISCOUNT_MAP, $storeId));
    }

    /**
     * The customer tax class charged to a buyer TackQuote marks tax exempt, or 0 when the
     * feature is off, not connected or no class is chosen (then nobody is exempted).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getTaxExemptClassId(?int $storeId = null): int
    {
        if (!$this->isLive(self::TAX_EXEMPT_ENABLED, $storeId)) {
            return 0;
        }
        $id = (int) $this->raw(self::TAX_EXEMPT_CLASS, $storeId);

        return $id > 0 ? $id : 0;
    }

    /**
     * The customer-group mirror applies on this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isMirrorActive(?int $storeId = null): bool
    {
        return $this->isLive(self::MIRROR_ENABLED, $storeId) && $this->getMirrorMap($storeId) !== [];
    }

    /**
     * @param int|null $storeId
     * @return array<string, int>
     */
    public function getMirrorMap(?int $storeId = null): array
    {
        return GroupRules::parseMirrorMap($this->raw(self::MIRROR_MAP, $storeId));
    }

    /**
     * Magento customer groups whose members the rules ask TackQuote about; [] = every
     * signed-in customer. Parsed by QuoteOnlyRules::normaliseGroups(), the module's one group
     * parser.
     *
     * @param int|null $storeId
     * @return int[]
     */
    public function getCustomerGroups(?int $storeId = null): array
    {
        return QuoteOnlyRules::normaliseGroups($this->raw(self::CUSTOMER_GROUPS, $storeId));
    }

    /**
     * Is ANY rule that needs the buyer group active? (The dashboard and the observers' cheap
     * first check.)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAnyRuleActive(?int $storeId = null): bool
    {
        return $this->isCatalogVisibilityActive($storeId)
            || $this->isRestrictionsActive($storeId)
            || $this->isDiscountsActive($storeId)
            || $this->getTaxExemptClassId($storeId) > 0
            || $this->isMirrorActive($storeId);
    }

    /**
     * @param string $field
     * @param int|null $storeId
     * @return bool
     */
    private function isLive(string $field, ?int $storeId): bool
    {
        return $this->isSwitchOn($field, $storeId) && $this->config->isConfigured($storeId);
    }

    /**
     * @param string $field
     * @param int|null $storeId
     * @return string
     */
    private function raw(string $field, ?int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
