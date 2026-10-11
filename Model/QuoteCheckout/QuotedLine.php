<?php
/**
 * The marker on a cart line that came from an accepted TackQuote quote.
 *
 * A quoted line carries its quote in the quote item's `additional_data` (a JSON object), next
 * to anything else a module keeps there. `additional_data` is the one quote-item column core
 * copies to the order item (magento2 Quote/etc/fieldset.xml, `quote_convert_item`, field
 * `additional_data`; Magento\Sales\Api\Data\OrderItemInterface::ADDITIONAL_DATA), so the order
 * keeps the reference after the cart is gone and TackQuote's order pull can read it back
 * (tack `magentoOrderQuoteRef`, magento/magento-quote-checkout.service.ts).
 *
 *   tackquote_quote_ref       the opaque checkout reference (`tqco_` + 22), sent back on completion
 *   tackquote_quote_number    the quote number the buyer accepted
 *   tackquote_quoted_price    the unit price charged (the line's custom price), cart currency
 *   tackquote_quoted_qty      the accepted quantity, which the line may not leave
 *   tackquote_quote_lines     how many lines the quote has, so a removed line is noticed
 *   tackquote_quote_currency  the quote's currency (ISO 4217)
 *
 * The price key is also the OWNERSHIP marker Model\Enforce\CustomPriceApplier respects: a line
 * that carries it is never repriced by the account-price enforcement.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item\AbstractItem;

class QuotedLine
{
    public const REF = 'tackquote_quote_ref';
    public const NUMBER = 'tackquote_quote_number';
    public const PRICE = 'tackquote_quoted_price';
    public const QTY = 'tackquote_quoted_qty';
    public const LINES = 'tackquote_quote_lines';
    public const CURRENCY = 'tackquote_quote_currency';

    /** The only checkout reference shape TackQuote mints. */
    public const REF_PATTERN = '/^tqco_[A-Za-z0-9_-]{22}$/';

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Json $json
     */
    public function __construct(Json $json)
    {
        $this->json = $json;
    }

    /**
     * The line's `additional_data` as a JSON object, [] when empty, null when it holds
     * anything else (someone else's data, never overwritten).
     *
     * @param mixed $raw
     * @return array|null
     */
    public function decode($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }

    /**
     * Whether the line names a quote at all (even a damaged marker). Used where the answer
     * must fail towards "leave it alone".
     *
     * @param AbstractItem $item
     * @return bool
     */
    public function isMarked(AbstractItem $item): bool
    {
        $data = $this->decode($item->getData('additional_data'));

        return is_array($data) && array_key_exists(self::REF, $data);
    }

    /**
     * The line's complete, well-formed quote marker, or null.
     *
     * @param AbstractItem $item
     * @return array{ref: string, number: string, price: float, qty: int, lines: int, currency: string}|null
     */
    public function read(AbstractItem $item): ?array
    {
        return self::fromData($this->decode($item->getData('additional_data')));
    }

    /**
     * The marker out of decoded `additional_data`, or null when any part is missing or malformed.
     *
     * @param array|null $data
     * @return array{ref: string, number: string, price: float, qty: int, lines: int, currency: string}|null
     */
    public static function fromData(?array $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $ref = $data[self::REF] ?? null;
        $number = $data[self::NUMBER] ?? null;
        $price = $data[self::PRICE] ?? null;
        $qty = $data[self::QTY] ?? null;
        $lines = $data[self::LINES] ?? null;
        $currency = $data[self::CURRENCY] ?? null;
        if (!is_string($ref) || preg_match(self::REF_PATTERN, $ref) !== 1
            || !is_string($number) || $number === ''
            || !(is_int($price) || is_float($price)) || $price < 0
            || !is_int($qty) || $qty < 1
            || !is_int($lines) || $lines < 1
            || !is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            return null;
        }

        return [
            'ref' => $ref,
            'number' => $number,
            'price' => (float) $price,
            'qty' => $qty,
            'lines' => $lines,
            'currency' => $currency,
        ];
    }

    /**
     * Write the marker onto a fresh line, keeping any other key already there.
     *
     * @param AbstractItem $item
     * @param array{ref: string, number: string, price: float, qty: int, lines: int, currency: string} $marker
     * @return bool False when the line's `additional_data` belongs to someone else.
     */
    public function stamp(AbstractItem $item, array $marker): bool
    {
        $data = $this->decode($item->getData('additional_data'));
        if ($data === null) {
            return false;
        }
        $data[self::REF] = $marker['ref'];
        $data[self::NUMBER] = $marker['number'];
        $data[self::PRICE] = $marker['price'];
        $data[self::QTY] = $marker['qty'];
        $data[self::LINES] = $marker['lines'];
        $data[self::CURRENCY] = $marker['currency'];
        $item->setData('additional_data', (string) $this->json->serialize($data));

        return true;
    }
}
