<?php
/**
 * Turns TackQuote's storefront answers into the facts the product page may show.
 *
 * Pure functions over decoded JSON, so every rule here is a unit test. The input shapes
 * are the server's answer unions (tack apps/api/src/modules/storefront-core/
 * storefront-results.ts); the rules for reading them follow the WooCommerce plugin 1.11.0
 * (class-tack-wholesale-pricing.php, class-tack-b2b-notices.php) so a buyer sees the same
 * thing on both platforms.
 *
 * EVERY RULE FAILS CLOSED. A status this mapper does not recognise, a missing field, a
 * non-numeric price or a currency other than the one the page shows all produce null,
 * which means "show nothing; Magento's own price stands". New server statuses are
 * therefore harmless to an installed store: the server adds `reason`s rather than new
 * `status` values for exactly this reason (storefront-results.ts header).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\B2bDisplay;

class ResponseMapper
{
    /**
     * Order-limit types that bound the QUANTITY of one product (OrderLimitType in tack
     * apps/api/src/modules/order-limits/order-limit-rule.entity.ts). An `order_total`
     * rule's min is MONEY; reading it as units would tell a buyer the wrong thing.
     */
    private const QUANTITY_LIMIT_TYPES = ['product_qty', 'per_product_qty'];

    /** Two prices closer than this are the same rung of a ladder. */
    private const PRICE_EPSILON = 0.00001;

    /**
     * `WholesalePriceResult` -> the account price, or null.
     *
     * Only a `priced` answer that TackQuote marks `accountSpecific` is shown. A priced
     * answer that is NOT account-specific is the catalogue list price ("correct to show,
     * but not this buyer's negotiated rate, and must not be labelled as one", tack
     * storefront-pricing.core.ts), and Magento's own price box already shows the store's
     * price, so repeating it under "Your account price" would be a false claim.
     *
     * @param mixed $answer Decoded response.
     * @param string $pageCurrency The currency the page shows (ISO 4217).
     * @return array{unitPrice: float, currency: string}|null
     */
    public function accountPrice($answer, string $pageCurrency): ?array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'priced') {
            return null;
        }
        if (($answer['accountSpecific'] ?? null) !== true) {
            return null;
        }
        $unit = $this->money($answer['unitPrice'] ?? null);
        $currency = $this->sameCurrency($answer['currency'] ?? null, $pageCurrency);
        if ($unit === null || $currency === null) {
            return null;
        }

        return ['unitPrice' => $unit, 'currency' => $currency];
    }

    /**
     * `QuantityBreakResult` -> a ladder of at least two DISTINCT rungs, ascending, or null.
     *
     * One rung is just the unit price, which is already on the page, and an empty table
     * under "Volume pricing" reads as "your discounts were removed" (WooCommerce
     * render_v1_quantity_breaks()). Rungs whose price equals the previous rung are folded.
     *
     * @param mixed $answer Decoded response.
     * @param string $pageCurrency
     * @return array{currency: string, accountSpecific: bool,
     *               rows: array<int, array{minQty: int, unitPrice: float}>}|null
     */
    public function quantityBreaks($answer, string $pageCurrency): ?array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'priced') {
            return null;
        }
        if (!isset($answer['rows']) || !is_array($answer['rows'])) {
            return null;
        }
        $currency = $this->sameCurrency($answer['currency'] ?? null, $pageCurrency);
        if ($currency === null) {
            return null;
        }

        $rows = [];
        foreach ($answer['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $unit = $this->money($row['unitPrice'] ?? null);
            $min = $row['minQty'] ?? null;
            if ($unit === null || !is_numeric($min)) {
                continue;
            }
            $rows[] = ['minQty' => max(1, (int) $min), 'unitPrice' => $unit];
        }
        usort(
            $rows,
            function (array $a, array $b): int {
                return $a['minQty'] <=> $b['minQty'];
            }
        );

        $distinct = [];
        $previous = null;
        foreach ($rows as $row) {
            if ($previous !== null && abs($row['unitPrice'] - $previous) < self::PRICE_EPSILON) {
                continue;
            }
            $distinct[] = $row;
            $previous = $row['unitPrice'];
        }
        if (count($distinct) < 2) {
            return null;
        }

        return [
            'currency' => $currency,
            'accountSpecific' => ($answer['accountSpecific'] ?? null) === true,
            'rows' => $distinct,
        ];
    }

    /**
     * `OrderLimitsNoticeResult` -> the first quantity bound for this product, or null.
     *
     * Same rule as WooCommerce Tack_B2B_Notices::limits_for(): only a `limited` answer,
     * only a quantity rule, and only one that carries at least one bound ("minimum 0" is
     * noise). Fields are `min`/`max` (StorefrontOrderLimit in order-limits.service.ts).
     *
     * @param mixed $answer Decoded response.
     * @return array{min: int|null, max: int|null, accountSpecific: bool}|null
     */
    public function orderLimit($answer): ?array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'limited') {
            return null;
        }
        if (!isset($answer['limits']) || !is_array($answer['limits'])) {
            return null;
        }

        foreach ($answer['limits'] as $limit) {
            if (!is_array($limit)
                || !in_array($limit['limitType'] ?? null, self::QUANTITY_LIMIT_TYPES, true)
            ) {
                continue;
            }
            $min = $this->quantity($limit['min'] ?? null);
            $max = $this->quantity($limit['max'] ?? null);
            if ($min === null && $max === null) {
                continue;
            }

            return [
                'min' => $min,
                'max' => $max,
                'accountSpecific' => ($answer['accountSpecific'] ?? null) === true,
            ];
        }

        return null;
    }

    /**
     * `BuyerGroupResult` -> the group's display name, or null.
     *
     * @param mixed $answer Decoded response.
     * @return array{name: string}|null
     */
    public function buyerGroup($answer): ?array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'grouped') {
            return null;
        }
        $name = $answer['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return ['name' => trim($name)];
    }

    /**
     * A non-negative, finite price, or null.
     *
     * Zero IS a price TackQuote can resolve, and is kept; `null`, a string that is not a
     * number, a boolean and a negative number are not prices.
     *
     * @param mixed $value
     * @return float|null
     */
    private function money($value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;

        return is_finite($amount) && $amount >= 0 ? $amount : null;
    }

    /**
     * A positive whole quantity, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function quantity($value): ?int
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $qty = (int) $value;

        return $qty >= 1 ? $qty : null;
    }

    /**
     * The answer's currency when it is the page's, else null.
     *
     * The request already names the page currency and the server withholds a mismatched
     * price (`unpriced` / `currency_mismatch`, storefront-currency.ts); this repeats the
     * check here so a price can never appear beside a product priced in another currency,
     * whatever an older server does. No conversion: an exchange rate this module picked
     * would be a price nobody set.
     *
     * @param mixed $answerCurrency
     * @param string $pageCurrency
     * @return string|null
     */
    private function sameCurrency($answerCurrency, string $pageCurrency): ?string
    {
        if (!is_string($answerCurrency) || $answerCurrency === '' || $pageCurrency === '') {
            return null;
        }
        $answerCurrency = strtoupper($answerCurrency);

        return $answerCurrency === strtoupper($pageCurrency) ? $answerCurrency : null;
    }
}
