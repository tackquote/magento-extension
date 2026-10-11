<?php
/**
 * The accepted-quote cart TackQuote hands over on the token exchange, validated, or null.
 *
 * WIRE CONTRACT: the authority is the TackQuote server, `MagentoQuoteCheckoutPayload` in tack
 * apps/api/src/modules/integrations/magento/magento-quote-checkout.service.ts, answered by
 * `GET /integrations/magento/quote-checkout/:token` (MagentoQuoteCheckoutController):
 *
 *   { quoteRef: "tqco_…", quoteNumber, currency: "USD", buyerEmail, poNumber?,
 *     lines: [{ sku, name, quantity: int, unitPrice: "12.3456", lineTotal }], goodsTotal }
 *
 * Anything this module cannot read exactly is refused as a whole (null): a cart is built from
 * every line of the quote or not at all, the WooCommerce plugin's rule
 * (Tack_Quote_Checkout::read_payload()). Two lines with one SKU are refused here too, because
 * Magento merges two adds of one product into a single line that can hold one price.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

class PayloadReader
{
    /** A unit price as TackQuote sends it: a decimal string, at most 4 decimals. */
    public const PRICE_PATTERN = '/^\d{1,12}(\.\d{1,4})?$/';

    /** Upper bound on lines read from one payload. */
    public const MAX_LINES = 250;

    /** Upper bound on one line's quantity (TackQuote's own quote-request bound). */
    private const MAX_QUANTITY = 1000000;

    /**
     * @param mixed $data Decoded JSON.
     * @return array{quoteRef: string, quoteNumber: string, currency: string, buyerEmail: string,
     *               poNumber: string, lines: array<int, array{sku: string, name: string, quantity: int,
     *               unitPrice: string}>}|null
     */
    public function read($data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $ref = is_string($data['quoteRef'] ?? null) ? $data['quoteRef'] : '';
        $number = is_string($data['quoteNumber'] ?? null) ? trim($data['quoteNumber']) : '';
        $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '';
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        if (preg_match(QuotedLine::REF_PATTERN, $ref) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $number) !== 1
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $lines === [] || count($lines) > self::MAX_LINES || !array_is_list($lines)
        ) {
            return null;
        }

        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return null;
            }
            $sku = is_string($line['sku'] ?? null) ? trim($line['sku']) : '';
            $quantity = $line['quantity'] ?? null;
            $price = $line['unitPrice'] ?? null;
            if ($sku === '' || mb_strlen($sku) > 64
                || !is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY
                || !is_string($price) || preg_match(self::PRICE_PATTERN, $price) !== 1
            ) {
                return null;
            }
            $key = mb_strtolower($sku);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $name = is_string($line['name'] ?? null) ? trim(strip_tags($line['name'])) : '';
            $out[] = [
                'sku' => $sku,
                'name' => $name !== '' ? mb_substr($name, 0, 255) : $sku,
                'quantity' => $quantity,
                'unitPrice' => $price,
            ];
        }

        $email = is_string($data['buyerEmail'] ?? null) ? trim($data['buyerEmail']) : '';
        $po = is_string($data['poNumber'] ?? null) ? trim(strip_tags($data['poNumber'])) : '';

        return [
            'quoteRef' => $ref,
            'quoteNumber' => $number,
            'currency' => $currency,
            'buyerEmail' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'poNumber' => mb_substr($po, 0, 64),
            'lines' => $out,
        ];
    }
}
