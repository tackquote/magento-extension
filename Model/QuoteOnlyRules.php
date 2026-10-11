<?php
/**
 * Quote-only (B2B catalog) mode — the rule, as pure functions.
 *
 * WHY THIS IS A SEPARATE, DEPENDENCY-FREE CLASS.
 *
 * "Is this visitor quote-only right now?" is asked from four places that share nothing else:
 * the cart guard that refuses an add-to-cart POST (Plugin/Checkout/CartGuard.php), the
 * layout observer that removes the Add to Cart button (Observer/QuoteOnlyLayoutHandle.php),
 * the product-page block that must render a quote CTA in its place (Block/RequestQuote.php),
 * and the admin validation. If those four re-derived the rule from scope config
 * independently they would drift, and the worst drift available here is enforcement ON with
 * the CTA OFF — a storefront nobody can buy from and nobody can request a quote from.
 *
 * So the rule lives once, over primitives: no ScopeConfig, no Session, no ObjectManager.
 * That is also what makes it directly unit-testable and mutation-testable.
 *
 * TWO MAGENTO-SPECIFIC FACTS THAT CHANGE THE RULE, both verified against 2.4.8 source
 * rather than carried over from the OpenCart build of this feature:
 *
 *  1. **Customer group 0 is a REAL group in Magento**, not a sentinel. It is
 *     `Magento\Customer\Model\GroupManagement::NOT_LOGGED_IN_ID`, it exists as a row in the
 *     customer-group grid, and `Magento\Customer\Model\Session::getCustomerGroupId()`
 *     (vendor/magento/module-customer/Model/Session.php:381) returns it for a guest. So 0
 *     is ACCEPTED in a group selection here. The OpenCart module deliberately drops 0
 *     because OpenCart leaves the field at 0 as a "no group" sentinel; copying that
 *     behaviour here would silently delete the merchant's "NOT LOGGED IN" selection.
 *
 *  2. **Magento multiselect config values are stored as a comma-separated STRING**, not an
 *     array, so normaliseGroups() has to accept both.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

final class QuoteOnlyRules
{
    /** Every storefront visitor is quote-only. The default. */
    public const SCOPE_ALL = 'all';

    /**
     * Only visitors who are not signed in. This is the "approved B2B customers keep the
     * cart" setting: a catalog to the public, a shop to account holders.
     */
    public const SCOPE_GUESTS = 'guests';

    /** Only visitors whose customer group is in the selected set. */
    public const SCOPE_GROUPS = 'groups';

    /**
     * Everyone EXCEPT a signed-in customer whose TackQuote wholesale application is
     * approved (`GET /storefront/v1/price-access` answers `{status: linked,
     * wholesaleApproved: true}`). Guests, unknown buyers, unapproved buyers and every
     * lookup failure are quote-only: see applies() and Model\QuoteOnly\WholesaleApproval.
     */
    public const SCOPE_UNAPPROVED = 'unapproved';

    /** The product attribute that makes one product quote-only (Setup/Patch/Data). */
    public const PRODUCT_ATTRIBUTE = 'tackquote_quote_only';

    /**
     * @return string[]
     */
    public static function scopes(): array
    {
        return [self::SCOPE_ALL, self::SCOPE_GUESTS, self::SCOPE_GROUPS, self::SCOPE_UNAPPROVED];
    }

    /**
     * Anything unrecognised becomes SCOPE_ALL.
     *
     * Deliberately the STRICTEST scope rather than the most permissive: a hand-edited or
     * migrated `core_config_data` row must not quietly re-open the cart on a store whose
     * owner switched quote-only on. Whether the feature runs at all is decided separately,
     * by the merchant's explicit enable flag, which is passed into applies() as $enabled.
     *
     * @param mixed $scope
     */
    public static function normaliseScope($scope): string
    {
        $scope = is_string($scope) ? strtolower(trim($scope)) : '';

        return in_array($scope, self::scopes(), true) ? $scope : self::SCOPE_ALL;
    }

    /**
     * Customer group ids, however Magento hands them over.
     *
     * A `type="multiselect"` field is persisted as "0,1,3"; a store that has never saved the
     * field returns null; a fixture or `bin/magento config:set` can leave a real array. All
     * three are accepted.
     *
     * Group 0 is KEPT — see fact (1) in the class comment. Anything that is not a
     * non-negative integer is dropped rather than cast, so 'abc' does not silently become
     * group 0 and widen the rule to every guest on the store.
     *
     * @param mixed $groups
     * @return int[]
     */
    public static function normaliseGroups($groups): array
    {
        if (is_string($groups)) {
            $groups = preg_split('/\s*,\s*/', trim($groups), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!is_array($groups)) {
            return [];
        }

        $out = [];

        foreach ($groups as $group) {
            if (!is_scalar($group) || is_bool($group)) {
                continue;
            }

            $group = trim((string) $group);

            if ($group === '' || preg_match('/^\d+$/', $group) !== 1) {
                continue;
            }

            $id = (int) $group;

            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The whole rule, in one place.
     *
     * @param bool $enabled The merchant's explicit switch. Callers must ALSO have
     *     established that the module can actually reach TackQuote — see
     *     QuoteOnlyMode::isActive(), which refuses to enforce on an unconfigured store because
     *     enforcement without a working quote button is a dead storefront.
     * @param string $scope One of the SCOPE_* constants.
     * @param int[] $groupIds Selected customer groups, for SCOPE_GROUPS.
     * @param bool $isLoggedIn Whether the visitor is signed in.
     * @param int $customerGroupId The visitor's customer group — used as-is, including 0 for a
     *     guest, because 0 is a real group here (fact (1) above).
     * @param bool $isApprovedWholesale For SCOPE_UNAPPROVED only: TackQuote answered that this
     *     signed-in customer's wholesale application is approved. Callers pass FALSE for
     *     anything else — no answer, an error, a guest — which is what makes the scope fail
     *     closed (quote-only), like the WooCommerce plugin's 1.11.0 price gate.
     * @return bool
     */
    public static function applies(
        bool $enabled,
        string $scope,
        array $groupIds,
        bool $isLoggedIn,
        int $customerGroupId,
        bool $isApprovedWholesale = false
    ): bool {
        if (!$enabled) {
            return false;
        }

        switch (self::normaliseScope($scope)) {
            case self::SCOPE_GUESTS:
                return !$isLoggedIn;

            case self::SCOPE_GROUPS:
                // An empty selection matches nobody, so the cart stays open. The admin
                // refuses to save that combination, but a hand-edited row must fail towards
                // a working store rather than a locked one.
                return in_array($customerGroupId, self::normaliseGroups($groupIds), true);

            case self::SCOPE_UNAPPROVED:
                // Both halves are required: an approval answer for a visitor who is not
                // signed in cannot be about them.
                return !($isLoggedIn && $isApprovedWholesale);

            case self::SCOPE_ALL:
            default:
                return true;
        }
    }

    /**
     * The per-product switch, however Magento hands it over.
     *
     * A `boolean` EAV attribute is stored as int 0/1 in `catalog_product_entity_int`, but it
     * reaches PHP as '1', 1, true, null (never set) or false (getAttributeRawValue() on a
     * store where the attribute does not exist yet). Only an explicit 1 switches a product
     * to quote-only; everything else leaves it purchasable, so a missing or half-installed
     * attribute can never lock the catalogue.
     *
     * @param mixed $raw
     */
    public static function isFlagOn($raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1;
        }

        return is_string($raw) && trim($raw) === '1';
    }

    /**
     * Whether a TackQuote `price-access` answer says the buyer is an approved wholesale
     * buyer. Strict: only `{status: "linked", wholesaleApproved: true}` (the boolean `true`,
     * not a truthy string) is approval. The server's union is
     * `anonymous | unlinked | linked{wholesaleApproved: boolean}`
     * (tack apps/api/src/modules/storefront-core/storefront-results.ts:96-99), so anything
     * else is a shape this module does not understand and is NOT approval.
     *
     * @param mixed $answer The decoded JSON object.
     */
    public static function isApprovedAnswer($answer): bool
    {
        return is_array($answer)
            && ($answer['status'] ?? null) === 'linked'
            && ($answer['wholesaleApproved'] ?? null) === true;
    }

    /**
     * Whether prices give way to "Price on request" for this visitor.
     *
     * Only where the STORE-WIDE mode applies to them, never because of a single product:
     * a per-product quote-only item keeps its price and gains an "Available on quote" label,
     * which is the WooCommerce plugin's behaviour (1.10.0+).
     *
     * @param bool $storeWideModeActive QuoteOnlyMode::isActive() for this visitor.
     * @param bool $hidePricesSwitch    The merchant's `quote_only/hide_prices` setting.
     * @return bool
     */
    public static function hidesPrices(bool $storeWideModeActive, bool $hidePricesSwitch): bool
    {
        return $storeWideModeActive && $hidePricesSwitch;
    }
}
