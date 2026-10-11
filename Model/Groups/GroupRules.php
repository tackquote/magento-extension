<?php
/**
 * Buyer-group rules: parsing the merchant's settings and deciding them. Pure functions only.
 *
 * SOURCE OF THE SEMANTICS: the WooCommerce plugin 1.11.0, read rule by rule (tackquote/
 * woocommerce, includes/):
 *
 *   class-tack-group-restrictions.php  payment and shipping methods per group (allow-list,
 *                                      unconfigured = unrestricted, `parse_map()`,
 *                                      `permitted()`), shipping discounts per group
 *                                      (`parse_discount_map()`, `apply_shipping_discounts()`)
 *   class-tack-catalog-visibility.php  categories HIDDEN from the groups named (deny-list),
 *                                      `@GUESTS` for everybody outside a group, children
 *                                      inherit, unknown group = shown unless the merchant
 *                                      ticks "Hide when the buyer group is unknown"
 *                                      (`hidden_term_ids()`, `audience()`)
 *
 * The stored format is the WooCommerce one, one rule per line, so a merchant who runs both
 * stores writes the same thing in both:
 *
 *   payment / shipping   <method>: CODE, CODE         `checkmo: TRADE, GOLD`
 *   categories           <category id>: CODE, @GUESTS `12: TIER2, @GUESTS`
 *   shipping discounts   CODE: free_only | free [| methods] | percent=15 [| methods]
 *   customer-group mirror CODE: <Magento customer group id>   (Magento only, see
 *                         Model\Groups\CustomerGroupMirror)
 *
 * Blank lines and lines starting with `#` are ignored; at runtime an unreadable line is
 * skipped rather than throwing (a typo must not take the checkout down, as in WooCommerce),
 * and the admin refuses to SAVE one (Model\Groups\Config\RuleMapBackend), which WooCommerce's
 * checkbox grid made unnecessary there.
 *
 * Codes are compared case-INSENSITIVELY: both sides are upper-cased here. WooCommerce's own
 * note on why (`permitted()`): a lower-case code typed against TackQuote's upper-case one
 * would otherwise match nothing and hide that method from every customer, permanently.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

class GroupRules
{
    /** Everybody outside a group: guests, ungrouped and unlinked customers. */
    public const GUESTS = '@GUESTS';

    /** TackQuote's `buyer_groups.code` column is varchar(100). */
    public const MAX_CODE_LENGTH = 100;

    public const DISCOUNT_FREE_ONLY = 'free_only';
    public const DISCOUNT_FREE = 'free';
    public const DISCOUNT_PERCENT = 'percent';

    /** Formats RuleMapBackend validates. */
    public const FORMAT_METHODS = 'methods';
    public const FORMAT_CATEGORIES = 'categories';
    public const FORMAT_DISCOUNTS = 'discounts';
    public const FORMAT_MIRROR = 'mirror';

    /**
     * One normalised group code: trimmed, upper-cased, at most 100 characters.
     *
     * Deliberately not an allow-list: a code already in TackQuote with an unusual character
     * must still match (WooCommerce's `clean_stored_group_code()` reasoning). What a merchant
     * may TYPE is checked by isTypedCodeValid() instead.
     *
     * @param mixed $code
     * @return string
     */
    public static function normaliseCode($code): string
    {
        if (!is_scalar($code) || is_bool($code)) {
            return '';
        }

        return strtoupper(mb_substr(trim((string) $code), 0, self::MAX_CODE_LENGTH));
    }

    /**
     * May a merchant type this code? Letters, digits, `.`, `_` and `-`, or `@GUESTS`, the
     * WooCommerce settings' allow-list (`clean_group_code()`), which is also why no typed
     * code can collide with `@GUESTS`.
     *
     * @param string $code Already normalised.
     * @return bool
     */
    public static function isTypedCodeValid(string $code): bool
    {
        return $code === self::GUESTS || preg_match('/^[A-Z0-9._\-]{1,100}$/', $code) === 1;
    }

    /**
     * `<key>: CODE, CODE` lines → key => upper-cased codes. Port of WooCommerce `parse_map()`.
     *
     * A key with no code is dropped (unconfigured = unrestricted); a later line for the same
     * key replaces an earlier one.
     *
     * @param mixed $raw
     * @return array<string, string[]>
     */
    public static function parseMap($raw): array
    {
        $out = [];
        foreach (self::lines($raw) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $key = trim($parts[0]);
            if ($key === '') {
                continue;
            }
            $codes = [];
            foreach (explode(',', $parts[1]) as $code) {
                $code = self::normaliseCode($code);
                if ($code !== '' && !in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
            if ($codes !== []) {
                $out[$key] = $codes;
            }
        }

        return $out;
    }

    /**
     * The category map: only keys that are a positive whole number (a Magento category id).
     *
     * Ids, not URL keys, as WooCommerce uses term ids: renaming a URL key must not publish a
     * trade range.
     *
     * @param mixed $raw
     * @return array<int, string[]>
     */
    public static function parseCategoryMap($raw): array
    {
        $out = [];
        foreach (self::parseMap($raw) as $key => $codes) {
            $key = (string) $key;
            if (preg_match('/^[1-9][0-9]{0,9}$/', $key) === 1) {
                $out[(int) $key] = $codes;
            }
        }

        return $out;
    }

    /**
     * Shipping discount rules. Port of WooCommerce `parse_discount_map()`:
     *
     *   CODE: free_only                 keep only the rates that already cost 0
     *   CODE: free [| m1, m2]           set these methods to 0 (no list = every method)
     *   CODE: percent=15 [| m1, m2]     take 15% off these methods (no list = every method)
     *
     * A percentage must be above 0 and at most 100 (100 is "free"). Unreadable lines are
     * skipped; a later line for the same code wins.
     *
     * @param mixed $raw
     * @return array<string, array{mode: string, percent: float, methods: string[]}>
     */
    public static function parseDiscountMap($raw): array
    {
        $out = [];
        foreach (self::lines($raw) as $line) {
            $rule = self::parseDiscountLine($line);
            if ($rule !== null) {
                $out[$rule['code']] = [
                    'mode' => $rule['mode'],
                    'percent' => $rule['percent'],
                    'methods' => $rule['methods'],
                ];
            }
        }

        return $out;
    }

    /**
     * The customer-group mirror map: CODE => Magento customer group id (a positive integer;
     * 0, NOT LOGGED IN, can never be assigned to an account).
     *
     * @param mixed $raw
     * @return array<string, int>
     */
    public static function parseMirrorMap($raw): array
    {
        $out = [];
        foreach (self::lines($raw) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $code = self::normaliseCode($parts[0]);
            $group = trim($parts[1]);
            if ($code === '' || $code === self::GUESTS || preg_match('/^[1-9][0-9]{0,9}$/', $group) !== 1) {
                continue;
            }
            $out[$code] = (int) $group;
        }

        return $out;
    }

    /**
     * Line numbers (1-based) the admin should refuse to save, for one format.
     *
     * @param string $raw
     * @param string $format One of the FORMAT_* constants.
     * @return int[]
     */
    public static function invalidLines(string $raw, string $format): array
    {
        $bad = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (!self::isLineValid($line, $format)) {
                $bad[] = $i + 1;
            }
        }

        return $bad;
    }

    /**
     * Who is shopping, for a rule: an upper-cased group code, GUESTS, or null when the group
     * could not be read. Port of WooCommerce `Tack_Catalog_Visibility::audience()`.
     *
     * @param BuyerGroupAnswer $answer
     * @return string|null
     */
    public static function audienceOf(BuyerGroupAnswer $answer): ?string
    {
        switch ($answer->getStatus()) {
            case BuyerGroupAnswer::GROUPED:
                return $answer->getCode();
            case BuyerGroupAnswer::GUEST:
            case BuyerGroupAnswer::NONE:
            case BuyerGroupAnswer::ANONYMOUS:
                return self::GUESTS;
            default:
                return null;
        }
    }

    /**
     * The category ids (before children are added) hidden from this shopper.
     *
     * Port of WooCommerce `hidden_term_ids()`: a category is hidden from the codes its line
     * names. When the group could not be read, nothing is hidden unless the merchant chose the
     * strict reading, which hides every category hidden from anyone. An exempt caller (admin,
     * integration) is never filtered.
     *
     * @param array<int, string[]> $map From parseCategoryMap().
     * @param BuyerGroupAnswer $answer
     * @param bool $hideWhenUnknown
     * @return int[]
     */
    public static function hiddenCategoryRoots(array $map, BuyerGroupAnswer $answer, bool $hideWhenUnknown): array
    {
        if ($map === [] || $answer->isExempt()) {
            return [];
        }

        $audience = self::audienceOf($answer);
        $ids = [];
        foreach ($map as $categoryId => $codes) {
            $categoryId = (int) $categoryId;
            if ($categoryId <= 0) {
                continue;
            }
            if ($audience === null) {
                if ($hideWhenUnknown) {
                    $ids[] = $categoryId;
                }
                continue;
            }
            if (in_array($audience, $codes, true)) {
                $ids[] = $categoryId;
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * May this shopper use the method configured under `$key`?
     *
     * Port of WooCommerce `permitted()`:
     *   - a key with no rule is UNRESTRICTED (switching the feature on changes nothing until a
     *     rule exists);
     *   - a grouped shopper may use it when their code is listed;
     *   - a shopper TackQuote answered for, outside any group (and a guest), may not, unless
     *     the line lists `@GUESTS` (an addition to the WooCommerce format);
     *   - when TackQuote could not be asked the method stays available (an outage must not
     *     remove the ability to pay or ship) unless the merchant chose the strict reading.
     *
     * @param array<string, string[]> $map From parseMap().
     * @param string $key
     * @param BuyerGroupAnswer $answer
     * @param bool $restrictWhenUnknown
     * @return bool
     */
    public static function isPermitted(array $map, string $key, BuyerGroupAnswer $answer, bool $restrictWhenUnknown): bool
    {
        if (!isset($map[$key]) || $map[$key] === [] || $answer->isExempt()) {
            return true;
        }

        $audience = self::audienceOf($answer);
        if ($audience === null) {
            return !$restrictWhenUnknown;
        }

        return in_array($audience, $map[$key], true);
    }

    /**
     * Which line of a shipping map governs a rate: the full rate code (`carrier_method`)
     * first, the carrier code second, evaluated ONCE.
     *
     * WooCommerce learned this the hard way (`filter_shipping_rates()`): checking both and
     * OR-ing the answers lets an unconfigured full id ("permitted") short-circuit the
     * carrier's rule, so every carrier-level restriction silently did nothing.
     *
     * @param array<string, mixed> $map
     * @param string $carrier
     * @param string $method
     * @return string
     */
    public static function shippingRuleKey(array $map, string $carrier, string $method): string
    {
        $full = $carrier . '_' . $method;

        return isset($map[$full]) ? $full : $carrier;
    }

    /**
     * Does a discount rule cover this rate? An empty method list covers every rate; otherwise
     * the full rate code or the carrier code must be listed.
     *
     * @param string[] $methods
     * @param string $carrier
     * @param string $method
     * @return bool
     */
    public static function discountCovers(array $methods, string $carrier, string $method): bool
    {
        if ($methods === []) {
            return true;
        }

        return in_array($carrier . '_' . $method, $methods, true) || in_array($carrier, $methods, true);
    }

    /**
     * The discounted shipping price for a `free` or `percent` rule.
     *
     * Rounded to 2 decimals, which is what Magento's own PriceCurrency::round() does
     * (DEFAULT_PRECISION = 2). Never below 0. A rate that already costs 0 is returned as is.
     *
     * @param float $price
     * @param array{mode: string, percent: float, methods: string[]} $rule
     * @return float
     */
    public static function discountedPrice(float $price, array $rule): float
    {
        if ($price <= 0) {
            return $price;
        }
        if ($rule['mode'] === self::DISCOUNT_FREE) {
            return 0.0;
        }
        $new = $price * (100 - (float) $rule['percent']) / 100;

        return max(0.0, round($new, 2));
    }

    /**
     * Trimmed, non-comment lines.
     *
     * @param mixed $raw
     * @return string[]
     */
    private static function lines($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, '#') !== 0) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * One discount line, or null when it does not parse.
     *
     * @param string $line
     * @return array{code: string, mode: string, percent: float, methods: string[]}|null
     */
    private static function parseDiscountLine(string $line): ?array
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $code = self::normaliseCode($parts[0]);
        if ($code === '') {
            return null;
        }
        $halves = explode('|', $parts[1], 2);
        $spec = strtolower(trim($halves[0]));
        $methods = [];
        if (isset($halves[1])) {
            foreach (explode(',', $halves[1]) as $method) {
                $method = trim($method);
                if ($method !== '') {
                    $methods[] = $method;
                }
            }
        }

        if ($spec === self::DISCOUNT_FREE_ONLY || $spec === self::DISCOUNT_FREE) {
            return ['code' => $code, 'mode' => $spec, 'percent' => 100.0, 'methods' => $methods];
        }
        if (preg_match('/^percent\s*=\s*([0-9]+(?:\.[0-9]+)?)$/', $spec, $m) === 1) {
            $pct = (float) $m[1];
            if ($pct <= 0 || $pct > 100) {
                return null;
            }

            return [
                'code' => $code,
                'mode' => $pct === 100.0 ? self::DISCOUNT_FREE : self::DISCOUNT_PERCENT,
                'percent' => $pct,
                'methods' => $methods,
            ];
        }

        return null;
    }

    /**
     * Strict check of one non-empty, non-comment line for the admin.
     *
     * @param string $line
     * @param string $format
     * @return bool
     */
    private static function isLineValid(string $line, string $format): bool
    {
        if ($format === self::FORMAT_DISCOUNTS) {
            $rule = self::parseDiscountLine($line);

            return $rule !== null && self::isTypedCodeValid($rule['code']) && $rule['code'] !== self::GUESTS;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return false;
        }
        $key = trim($parts[0]);

        if ($format === self::FORMAT_MIRROR) {
            $code = self::normaliseCode($key);

            return $code !== self::GUESTS && self::isTypedCodeValid($code)
                && preg_match('/^[1-9][0-9]{0,9}$/', trim($parts[1])) === 1;
        }

        $keyPattern = $format === self::FORMAT_CATEGORIES ? '/^[1-9][0-9]{0,9}$/' : '/^[A-Za-z0-9_]{1,64}$/';
        if (preg_match($keyPattern, $key) !== 1) {
            return false;
        }
        $codes = array_filter(array_map([self::class, 'normaliseCode'], explode(',', $parts[1])), 'strlen');
        if ($codes === []) {
            return false;
        }
        foreach ($codes as $code) {
            if (!self::isTypedCodeValid($code)) {
                return false;
            }
        }

        return true;
    }
}
