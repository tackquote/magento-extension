<?php
/**
 * Reads TackQuote's answers for checkout enforcement. Pure functions over decoded JSON, so
 * every rule is a unit test, and every rule FAILS CLOSED: anything unrecognised means "no
 * TackQuote price" (Magento's own price stands) or "no limit from this answer".
 *
 * WHAT MAY BE CHARGED — `POST /storefront-pricing/resolve` answers
 * `{buyerMatched, items: [{sku, quantity, unitPrice, source}]}`
 * (tack apps/api/src/modules/pricing-rules/storefront-pricing.controller.ts:83, :152-169). It
 * carries no `accountSpecific` flag and no currency. Only what the server itself treats as an
 * account price is charged:
 *
 *   - `buyerMatched` must be true: without a matched buyer even a price-book answer is the
 *     tenant's DEFAULT book, i.e. a stranger's price;
 *   - `source` must be `price_book`. That is the one source the server's own
 *     `isAccountPrice()` calls an account price unconditionally
 *     (pricing-rules/price-resolver.service.ts:353-358). `pricing_rule` is an account price
 *     only for some rule shapes, and the deciding field (`compiled`) is not on this route's
 *     wire, so it is NOT charged; `catalog` is the list price, `unpriced` is no price, and
 *     anything else is a source this module does not know. Each of those keeps Magento's price.
 *   - `unitPrice` must be a finite number >= 0. `null` is "TackQuote cannot price this SKU";
 *     `0` is a real price and is kept (the WooCommerce plugin draws the same line).
 *
 * WHAT CURRENCY THE NUMBER IS IN — a price-book number is in the tenant's (workspace)
 * currency: "a price book, a company price ... which the seller writes in the workspace
 * currency" and "the resolver prices in the tenant currency, so it skips books in any other"
 * (pricing-rules/storefront-number-currency.ts:46-48, :64-66). The resolve answer does not
 * say which currency that is, so it is confirmed with the `/storefront/v1/wholesale-price`
 * read the product page already uses, asked in the cart's currency: that route labels its
 * number and WITHHOLDS a number in another currency as `unpriced` / `currency_mismatch`
 * (storefront-core/storefront-pricing.core.ts:154-172, storefront-currency.ts:42-54).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

class AnswerMapper
{
    /** The currency was confirmed to be the cart's. */
    public const CURRENCY_MATCH = 'match';

    /** TackQuote prices this tenant in another currency: nothing may be charged. */
    public const CURRENCY_MISMATCH = 'mismatch';

    /** The answer could not settle it (failure, unlinked, unpriced): nothing may be charged. */
    public const CURRENCY_UNKNOWN = 'unknown';

    /** The only `source` charged. See the class docblock. */
    private const CHARGEABLE_SOURCE = 'price_book';

    /**
     * The key one cart line is priced under: its SKU and its whole quantity.
     *
     * @param string $sku
     * @param int $quantity
     * @return string
     */
    public static function lineKey(string $sku, int $quantity): string
    {
        return $sku . "\n" . $quantity;
    }

    /**
     * The chargeable unit prices in a resolve answer, keyed by lineKey().
     *
     * @param mixed $answer Decoded `/storefront-pricing/resolve` response.
     * @return array<string, float>
     */
    public function chargeablePrices($answer): array
    {
        if (!is_array($answer) || ($answer['buyerMatched'] ?? null) !== true) {
            return [];
        }
        if (!isset($answer['items']) || !is_array($answer['items'])) {
            return [];
        }

        $out = [];
        foreach ($answer['items'] as $line) {
            if (!is_array($line) || ($line['source'] ?? null) !== self::CHARGEABLE_SOURCE) {
                continue;
            }
            $sku = $line['sku'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $unit = $this->money($line['unitPrice'] ?? null);
            if (!is_string($sku) || $sku === '' || !is_int($quantity) || $quantity < 1 || $unit === null) {
                continue;
            }
            $out[self::lineKey($sku, $quantity)] = $unit;
        }

        return $out;
    }

    /**
     * What a `/storefront/v1/wholesale-price` answer, asked in $cartCurrency, says about the
     * currency TackQuote prices this tenant in.
     *
     * @param mixed $answer Decoded WholesalePriceResult.
     * @param string $cartCurrency ISO 4217.
     * @return string One of the CURRENCY_* constants.
     */
    public function currencyVerdict($answer, string $cartCurrency): string
    {
        $cartCurrency = strtoupper(trim($cartCurrency));
        if (!is_array($answer) || $cartCurrency === '') {
            return self::CURRENCY_UNKNOWN;
        }
        $currency = $answer['currency'] ?? null;
        if (!is_string($currency) || trim($currency) === '') {
            return self::CURRENCY_UNKNOWN;
        }
        $currency = strtoupper(trim($currency));
        $status = $answer['status'] ?? null;

        if ($status === 'priced') {
            // An older server labels without gating; the label still decides.
            return $currency === $cartCurrency ? self::CURRENCY_MATCH : self::CURRENCY_MISMATCH;
        }
        if ($status === 'unpriced' && ($answer['reason'] ?? null) === 'currency_mismatch'
            && $currency !== $cartCurrency
        ) {
            return self::CURRENCY_MISMATCH;
        }

        return self::CURRENCY_UNKNOWN;
    }

    /**
     * The order-total bounds in an `/storefront/v1/order-limits` answer that apply to a cart in
     * $cartCurrency.
     *
     * Money, not units. A rule in another currency does not apply; a rule with no currency
     * does. Both follow the server's own evaluation (order-limits/company-order-limits.ts:189-196,
     * "A total rule in another currency does not apply").
     *
     * @param mixed $answer Decoded OrderLimitsNoticeResult.
     * @param string $cartCurrency
     * @return array<int, array{min: float|null, max: float|null}>
     */
    public function orderTotalLimits($answer, string $cartCurrency): array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'limited'
            || !isset($answer['limits']) || !is_array($answer['limits'])
        ) {
            return [];
        }
        $cartCurrency = strtoupper(trim($cartCurrency));

        $out = [];
        foreach ($answer['limits'] as $limit) {
            if (!is_array($limit) || ($limit['limitType'] ?? null) !== 'order_total') {
                continue;
            }
            $currency = $limit['currency'] ?? null;
            if (is_string($currency) && trim($currency) !== ''
                && strtoupper(trim($currency)) !== $cartCurrency
            ) {
                continue;
            }
            $min = $this->money($limit['min'] ?? null);
            $max = $this->money($limit['max'] ?? null);
            if ($min === null && $max === null) {
                continue;
            }
            $out[] = ['min' => $min, 'max' => $max];
        }

        return $out;
    }

    /**
     * A non-negative, finite amount, or null. Booleans and numeric strings are not prices
     * on this wire (the server sends JSON numbers).
     *
     * @param mixed $value
     * @return float|null
     */
    private function money($value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $amount = (float) $value;

        return is_finite($amount) && $amount >= 0 ? $amount : null;
    }
}
