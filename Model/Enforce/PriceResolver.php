<?php
/**
 * What TackQuote says one signed-in customer's cart lines cost: ONE batched request for the
 * whole cart, a short cache, and a fresh answer whenever the caller demands one.
 *
 * THE REQUEST — `POST /storefront-pricing/resolve` (Model\Api\StorefrontClient::resolvePricing),
 * each distinct line at its own quantity, because a quantity break only exists at a quantity.
 * The server takes at most 50 lines; a larger cart is priced for its first 50 (in a stable
 * order) and the rest keep Magento's price, which is reported as a fallback.
 *
 * WHAT MAY BE CHARGED is decided by AnswerMapper (price-book prices for a matched buyer only)
 * and then by the CURRENCY CHECK below. Nothing else is ever returned as a price.
 *
 * THE CURRENCY CHECK — a price-book number is in the tenant's currency, which the resolve
 * answer does not name. One `/storefront/v1/wholesale-price` read for one of the priced lines,
 * asked in the cart's currency, settles it (AnswerMapper::currencyVerdict()). The verdict is
 * about the TackQuote account, not the shopper, so it is cached per store view and cart
 * currency for CURRENCY_TTL and is flushed with the module's settings (tag TACKQUOTE_CONFIG,
 * cleaned by Model\RegistrationConfigProvider on every save of the settings). A fresh
 * resolution asks again.
 *
 * CACHING — the key is a SHA-256 of the store, the customer id, the customer's email, the cart
 * currency and every line's SKU and quantity, so an entry can only be read back for the same
 * customer with the same cart; no email appears in a cache id. CACHE_TTL is short because a
 * price-book change should reach the cart quickly; it exists so that the several totals
 * collections Magento runs per page, and reloads, cost one request rather than many. Within one
 * PHP request the answer is also memoised, so the order-submit path and the totals collection
 * it triggers can never see two different answers.
 *
 * FRESH — the order submit asks with $fresh = true: memo, cache and back-off are all skipped,
 * TackQuote is asked again, and the new answer replaces the cached one. A price cached a minute
 * ago therefore never reaches an order (Plugin\Quote\AccountPriceSubmitPlugin).
 *
 * FAILURE — `fallback: true` with no prices: every line keeps Magento's price, and the order
 * records that (Observer\AccountPriceOrderComment). A failure is never cached as an answer; a
 * short per-cart back-off and, on HTTP 429, a store-wide one stop a page of totals collections
 * from re-asking at once.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\B2bDisplay\DataProvider;

class PriceResolver
{
    /** Seconds a cart's answer is reused for the same customer, lines and currency. */
    public const CACHE_TTL = 60;

    /** Seconds before a failed resolution of the same cart is retried (fresh ignores it). */
    public const FAILURE_BACKOFF_TTL = 30;

    /** Seconds the whole store stops asking after the resolve route answered 429. */
    public const THROTTLED_BACKOFF_TTL = 60;

    /** Seconds the account's currency verdict is reused. */
    public const CURRENCY_TTL = 600;

    private const CACHE_PREFIX = 'tackquote_enforce_price_';

    /**
     * The settings tag (Model\RegistrationConfigProvider::CACHE_TAG, cleaned on every save of the
     * module's settings): a new API key may be another TackQuote account in another currency.
     */
    private const CONFIG_CACHE_TAG = 'TACKQUOTE_CONFIG';

    /**
     * The store-wide 429 marker of the `/storefront/v1` reads, shared with the product page
     * (Model\B2bDisplay\DataProvider, `tackquote_b2b_throttled_<store>`): the currency read is
     * one of those routes and spends the same throttle.
     */
    private const V1_THROTTLED_PREFIX = 'tackquote_b2b_throttled_';

    /** What a caller gets when nothing may be charged because TackQuote could not be asked. */
    private const FALLBACK = ['prices' => [], 'fallback' => true];

    /**
     * @var StorefrontClient
     */
    private $client;

    /**
     * @var AnswerMapper
     */
    private $mapper;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var array<string, array{prices: array<string, float>, fallback: bool}>
     */
    private $memo = [];

    /**
     * @param StorefrontClient $client
     * @param AnswerMapper $mapper
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        StorefrontClient $client,
        AnswerMapper $mapper,
        CacheInterface $cache,
        Json $json
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * The chargeable unit price of each line, keyed by AnswerMapper::lineKey().
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     *        Model\Enforce\Eligibility::buyer().
     * @param array<int, array{sku: string, quantity: int}> $lines
     * @param bool $fresh Skip every cache and ask TackQuote now (the order submit).
     * @return array{prices: array<string, float>, fallback: bool}
     */
    public function resolve(array $buyer, array $lines, bool $fresh = false): array
    {
        $byKey = [];
        foreach ($lines as $line) {
            $byKey[AnswerMapper::lineKey($line['sku'], $line['quantity'])] = $line;
        }
        if ($byKey === []) {
            return ['prices' => [], 'fallback' => false];
        }
        ksort($byKey, SORT_STRING);

        $storeId = (int) $buyer['storeId'];
        $key = $this->cacheKey($buyer, array_keys($byKey));

        if (!$fresh) {
            if (isset($this->memo[$key])) {
                return $this->memo[$key];
            }
            $cached = $this->decode($this->cache->load($key));
            if ($cached !== null) {
                return $this->memo[$key] = $cached;
            }
            if ($this->cache->load($this->throttledKey($storeId)) || $this->cache->load($key . '_failed')) {
                return $this->memo[$key] = self::FALLBACK;
            }
        }

        $truncated = count($byKey) > StorefrontClient::RESOLVE_MAX_ITEMS;
        $asked = array_slice($byKey, 0, StorefrontClient::RESOLVE_MAX_ITEMS, true);

        $answer = $this->client->resolvePricing(array_values($asked), $buyer['email'], $storeId);
        if (!$answer['ok']) {
            return $this->memo[$key] = $this->fail($key, $storeId, (int) $answer['status']);
        }

        $prices = array_intersect_key($this->mapper->chargeablePrices($answer['data'] ?? null), $asked);

        if ($prices !== []) {
            $probe = $asked[(string) array_key_first($prices)];
            $verdict = $this->currencyVerdict($buyer, $probe['sku'], $probe['quantity'], $fresh);
            if ($verdict === AnswerMapper::CURRENCY_UNKNOWN) {
                return $this->memo[$key] = $this->fail($key, $storeId, 0);
            }
            if ($verdict !== AnswerMapper::CURRENCY_MATCH) {
                // TackQuote prices this account in another currency. Not a failure: the store's
                // price is the right price, and the product page withholds it the same way.
                $prices = [];
            }
        }

        $result = ['prices' => $prices, 'fallback' => $truncated];
        $this->cache->save(
            (string) $this->json->serialize($result),
            $key,
            [DataProvider::CACHE_TAG],
            self::CACHE_TTL
        );

        return $this->memo[$key] = $result;
    }

    /**
     * The cache id for one customer, currency and set of lines. Public so the test can pin
     * what varies it; hashed because the parts include an email address.
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     * @param string[] $lineKeys Sorted AnswerMapper::lineKey() values.
     * @return string
     */
    public function cacheKey(array $buyer, array $lineKeys): string
    {
        return self::CACHE_PREFIX . hash(
            'sha256',
            implode("\n\n", [
                (string) (int) $buyer['storeId'],
                (string) (int) $buyer['customerId'],
                strtolower(trim((string) $buyer['email'])),
                strtoupper(trim((string) $buyer['currency'])),
                implode("\n\n", $lineKeys),
            ])
        );
    }

    /**
     * Record a failure and return the fallback answer.
     *
     * @param string $key
     * @param int $storeId
     * @param int $status
     * @return array{prices: array<string, float>, fallback: bool}
     */
    private function fail(string $key, int $storeId, int $status): array
    {
        if ($status === 429) {
            $this->cache->save(
                '1',
                $this->throttledKey($storeId),
                [DataProvider::CACHE_TAG],
                self::THROTTLED_BACKOFF_TTL
            );
        }
        $this->cache->save('1', $key . '_failed', [DataProvider::CACHE_TAG], self::FAILURE_BACKOFF_TTL);

        return self::FALLBACK;
    }

    /**
     * Is the account priced in the cart's currency? See the class docblock.
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     * @param string $sku A line the resolve answer priced.
     * @param int $quantity
     * @param bool $fresh
     * @return string AnswerMapper::CURRENCY_*
     */
    private function currencyVerdict(array $buyer, string $sku, int $quantity, bool $fresh): string
    {
        $storeId = (int) $buyer['storeId'];
        $currency = strtoupper(trim((string) $buyer['currency']));
        $key = self::CACHE_PREFIX . 'currency_' . hash('sha256', $storeId . "\n" . $currency);

        if (!$fresh) {
            $cached = $this->cache->load($key);
            if ($cached === AnswerMapper::CURRENCY_MATCH || $cached === AnswerMapper::CURRENCY_MISMATCH) {
                return $cached;
            }
            if ($this->cache->load(self::V1_THROTTLED_PREFIX . $storeId)) {
                return AnswerMapper::CURRENCY_UNKNOWN;
            }
        }

        $answer = $this->client->get(
            StorefrontClient::ROUTE_WHOLESALE_PRICE,
            [
                'buyerEmail' => $buyer['email'],
                'sku' => $sku,
                'quantity' => (string) $quantity,
                'currency' => $currency,
            ],
            $storeId
        );
        if (!$answer['ok']) {
            if ((int) $answer['status'] === 429) {
                $this->cache->save(
                    '1',
                    self::V1_THROTTLED_PREFIX . $storeId,
                    [DataProvider::CACHE_TAG],
                    DataProvider::THROTTLED_BACKOFF_TTL
                );
            }

            return AnswerMapper::CURRENCY_UNKNOWN;
        }

        $verdict = $this->mapper->currencyVerdict($answer['data'] ?? null, $currency);
        if ($verdict !== AnswerMapper::CURRENCY_UNKNOWN) {
            $this->cache->save($verdict, $key, [DataProvider::CACHE_TAG, self::CONFIG_CACHE_TAG], self::CURRENCY_TTL);
        }

        return $verdict;
    }

    /**
     * The resolve route's own store-wide 429 marker.
     *
     * @param int $storeId
     * @return string
     */
    private function throttledKey(int $storeId): string
    {
        return self::CACHE_PREFIX . 'throttled_' . $storeId;
    }

    /**
     * A cached answer, or null when absent or not of the expected shape.
     *
     * @param mixed $cached
     * @return array{prices: array<string, float>, fallback: bool}|null
     */
    private function decode($cached): ?array
    {
        if (!is_string($cached) || $cached === '') {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($cached);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($decoded) || !is_array($decoded['prices'] ?? null) || !is_bool($decoded['fallback'] ?? null)) {
            return null;
        }

        $prices = [];
        foreach ($decoded['prices'] as $lineKey => $price) {
            // JSON has no float type (9.0 comes back as 9). A damaged entry is refetched, never
            // charged.
            if (!is_string($lineKey) || (!is_int($price) && !is_float($price)) || $price < 0) {
                return null;
            }
            $prices[$lineKey] = (float) $price;
        }

        return ['prices' => $prices, 'fallback' => $decoded['fallback']];
    }
}
