<?php
/**
 * The two optional extras of a quote request: a target price per line and attached files.
 *
 * Pure functions, so every rule is unit-tested without a store. Controller\Quote\Submit
 * builds the rest of the payload; this class decides how the extras travel.
 *
 * WIRE CONTRACT — the authority is the TackQuote server, not this file:
 * tack apps/api/src/modules/integrations/plugin-quote-attachments.ts
 * (`PluginAttachmentQuoteRequestDto`, bound by `POST /integrations/magento/quote-requests`)
 * and storefront-plugin-ingress.dto.ts (`StorefrontPluginLineItemDto.targetPrice`):
 *
 *   lineItems[].targetPrice  number, >= 0, at most 4 decimals, at most 1e12
 *   uploadIds                at most 3 v4 UUIDs from `POST /storefront/v1/quote-upload`
 *   uploadToken              43 characters [A-Za-z0-9_-], the guest token that upload issued
 *
 * Each travels only when the server's `ping` lists the matching capability
 * (Model\ServerCapabilities). Without `targetPrice` the target prices are written into the
 * note instead, exactly as the WooCommerce plugin does for an older server
 * (Tack_Widget::with_target_prices()), so nothing the shopper typed is lost.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

class QuotePayloadBuilder
{
    /** TackQuote's MAX_PLUGIN_AMOUNT: a larger value makes it refuse the whole request. */
    public const MAX_TARGET_PRICE = 1000000000000;

    /** The server accepts at most 4 decimal places (`maxDecimalPlaces: 4`). */
    public const TARGET_DECIMALS = 4;

    /** Keeps a note with folded target prices inside the API's 10,000-character limit. */
    public const MAX_NOTE_WITH_TARGETS = 9000;

    /** A TackQuote upload id: a v4 UUID, lower case. */
    private const UPLOAD_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** The guest token TackQuote issues: 32 random bytes as base64url. */
    private const UPLOAD_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * A shopper's target unit price, as typed.
     *
     * Blank means "none". Anything else must be a plain non-negative number within the
     * server's bounds, or the request is refused with a message: silently dropping a value
     * the shopper typed would send a quote they did not ask for.
     *
     * @param mixed $raw
     * @return array{ok: bool, value: float|null}
     */
    public function parseTargetPrice($raw): array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return ['ok' => true, 'value' => null];
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            // A decimal comma is how much of the world writes a price.
            if (preg_match('/^\d+,\d+$/', $raw) === 1) {
                $raw = str_replace(',', '.', $raw);
            }
        }
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && preg_match('/^\d+(\.\d+)?$/', $raw) === 1)) {
            return ['ok' => false, 'value' => null];
        }

        $value = (float) $raw;
        if (!is_finite($value) || $value < 0 || $value > self::MAX_TARGET_PRICE) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => round($value, self::TARGET_DECIMALS)];
    }

    /**
     * The payload with the shopper's target prices placed where the server can take them.
     *
     * Lines carry `targetPrice` from Controller\Quote\Submit. With `$perLine` (the server
     * lists `targetPrice`) they stay on the lines; TackQuote stores each as the line's
     * requested price ("Buyer asked for …"), never as the line price. Otherwise they are
     * removed from the lines and written into the note, one row per product.
     *
     * @param array $payload Request payload with `lineItems` and `note`.
     * @param bool $perLine Whether the server takes `lineItems[].targetPrice`.
     * @param string $currency ISO 4217 code, or '' when unknown.
     * @return array
     */
    public function applyTargetPrices(array $payload, bool $perLine, string $currency): array
    {
        $rows = [];
        foreach ((array) ($payload['lineItems'] ?? []) as $i => $line) {
            if (!is_array($line) || !array_key_exists('targetPrice', $line)) {
                continue;
            }
            if ($line['targetPrice'] === null) {
                unset($payload['lineItems'][$i]['targetPrice']);
                continue;
            }
            if ($perLine) {
                $payload['lineItems'][$i]['targetPrice'] = (float) $line['targetPrice'];
                continue;
            }
            unset($payload['lineItems'][$i]['targetPrice']);
            $rows[] = (string) __(
                '- %1 (%2) x%3: %4 %5',
                (string) ($line['name'] ?? ''),
                (string) ($line['sku'] ?? '') !== '' ? (string) $line['sku'] : '-',
                (int) ($line['quantity'] ?? 0),
                $this->formatTarget((float) $line['targetPrice']),
                $currency
            );
        }

        if ($rows !== []) {
            $note = (string) ($payload['note'] ?? '');
            $appendix = (string) __('Target prices:') . "\n" . implode("\n", $rows);
            $payload['note'] = mb_substr(
                trim($note) === '' ? $appendix : $note . "\n\n" . $appendix,
                0,
                self::MAX_NOTE_WITH_TARGETS
            );
        }

        return $payload;
    }

    /**
     * A target price for the note: two decimals, or four when the shopper typed more.
     *
     * @param float $value
     * @return string
     */
    private function formatTarget(float $value): string
    {
        $places = abs($value - round($value, 2)) < 0.00001 ? 2 : self::TARGET_DECIMALS;

        return number_format($value, $places, '.', '');
    }

    /**
     * The upload ids and guest token a request may forward, or why not.
     *
     * Only shape is checked here. Whether the files exist, belong to this store and to this
     * shopper is TackQuote's claim, which runs in the quote's own transaction and refuses
     * the whole request otherwise.
     *
     * @param mixed $rawIds Comma-separated string or array.
     * @param mixed $rawToken
     * @return array{ok: bool, uploadIds?: string[], uploadToken?: string}
     */
    public function attachmentFields($rawIds, $rawToken): array
    {
        $ids = is_array($rawIds) ? $rawIds : (is_string($rawIds) && $rawIds !== '' ? explode(',', $rawIds) : []);
        $clean = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                return ['ok' => false];
            }
            $id = strtolower(trim($id));
            if ($id === '') {
                continue;
            }
            if (preg_match(self::UPLOAD_ID_PATTERN, $id) !== 1) {
                return ['ok' => false];
            }
            $clean[$id] = true;
        }
        $clean = array_keys($clean);

        if ($clean === []) {
            return ['ok' => true, 'uploadIds' => []];
        }
        if (count($clean) > AttachmentValidator::MAX_FILES) {
            return ['ok' => false];
        }

        $token = is_string($rawToken) ? trim($rawToken) : '';
        if ($token !== '' && preg_match(self::UPLOAD_TOKEN_PATTERN, $token) !== 1) {
            return ['ok' => false];
        }

        $out = ['ok' => true, 'uploadIds' => $clean];
        if ($token !== '') {
            $out['uploadToken'] = $token;
        }

        return $out;
    }

    /**
     * Whether a value is a TackQuote upload id.
     *
     * @param mixed $id
     * @return bool
     */
    public function isUploadId($id): bool
    {
        return is_string($id) && preg_match(self::UPLOAD_ID_PATTERN, $id) === 1;
    }

    /**
     * Whether a value is a TackQuote guest upload token.
     *
     * @param mixed $token
     * @return bool
     */
    public function isUploadToken($token): bool
    {
        return is_string($token) && preg_match(self::UPLOAD_TOKEN_PATTERN, $token) === 1;
    }
}
