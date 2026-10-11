<?php
/**
 * What TackQuote says about one signed-in customer and one product, for display.
 *
 * Asks only the routes whose switch is on, maps the answers through ResponseMapper, and
 * caches the result briefly in Magento's own cache.
 *
 * CACHING, AND WHY IT IS SAFE HERE — every answer depends on WHO is asking. The server
 * marks them `Cache-Control: private, no-store` so that no shared HTTP cache can hand one
 * shopper's price to another. This cache is server-side and its key carries the store, the
 * customer id, the customer's email, the SKU, the page currency and the feature set, so an
 * entry can only ever be read back for the same customer looking at the same product. The
 * key is a SHA-256 of those parts, so no email address appears in a cache id (Redis keys
 * and var/cache file names are readable by anyone with server access). The TTL is short
 * (CACHE_TTL) because a price-book change in TackQuote should reach the page quickly; it
 * exists to absorb reloads and back-button visits, which would otherwise spend the
 * store's request budget (every /storefront/v1 route is throttled at 60 per minute,
 * StorefrontV1Controller @Throttle).
 *
 * The WooCommerce plugin keeps these answers for one PHP request only and notes that a
 * longer-lived cache "must include the buyer" (class-tack-wholesale-pricing.php
 * `$resolved`). This one does.
 *
 * FAILURE — a route that fails contributes nothing (fail closed, Magento's price stands),
 * and the result is then NOT cached as if it were complete. Instead:
 *   - a short per-customer-and-product back-off stops a reload loop re-asking at once;
 *   - an HTTP 429 opens a store-wide back-off, because the throttle the store hit is the
 *     store's, not this shopper's, and every further call would only extend it.
 *
 * ABUSE — the endpoint is only reachable by a signed-in customer, but one account could
 * still walk the catalogue to spend the store's TackQuote budget. CUSTOMER_LOOKUP_BUDGET
 * caps uncached lookups per customer per minute; over it, the answer is "no B2B data".
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\B2bDisplay;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Config;

class DataProvider
{
    public const CACHE_TAG = 'TACKQUOTE_B2B';
    private const CACHE_PREFIX = 'tackquote_b2b_';

    /** Seconds a complete answer is reused for the same customer, product and currency. */
    public const CACHE_TTL = 60;

    /** Seconds before a failed lookup for the same customer and product is retried. */
    public const FAILURE_BACKOFF_TTL = 30;

    /** Seconds the whole store stops asking after TackQuote answered 429. */
    public const THROTTLED_BACKOFF_TTL = 60;

    /** Uncached lookups one customer may cause per minute. */
    public const CUSTOMER_LOOKUP_BUDGET = 30;

    /** Every key a result carries; null means "show nothing for this". */
    public const EMPTY_RESULT = [
        'accountPrice' => null,
        'quantityBreaks' => null,
        'orderLimit' => null,
        'buyerGroup' => null,
    ];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StorefrontClient
     */
    private $client;

    /**
     * @var ResponseMapper
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
     * @param Config $config
     * @param StorefrontClient $client
     * @param ResponseMapper $mapper
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        Config $config,
        StorefrontClient $client,
        ResponseMapper $mapper,
        CacheInterface $cache,
        Json $json
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->mapper = $mapper;
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * The B2B facts to show this customer for this product.
     *
     * @param int $storeId
     * @param int $customerId
     * @param string $email The signed-in customer's own email address.
     * @param string $sku
     * @param string $pageCurrency The currency the page shows (ISO 4217).
     * @param string[]|null $only Ask only for these features (still only if switched on);
     *                            null for every enabled one.
     * @return array{accountPrice: array|null, quantityBreaks: array|null,
     *               orderLimit: array|null, buyerGroup: array|null}
     */
    public function get(
        int $storeId,
        int $customerId,
        string $email,
        string $sku,
        string $pageCurrency,
        ?array $only = null
    ): array {
        $email = strtolower(trim($email));
        $sku = trim($sku);
        $pageCurrency = strtoupper(trim($pageCurrency));
        $features = $this->config->getEnabledB2bFeatures($storeId);
        if ($only !== null) {
            $features = array_values(array_intersect($features, $only));
        }

        if ($features === [] || $customerId < 1 || $email === '' || $sku === '' || $pageCurrency === '') {
            return self::EMPTY_RESULT;
        }

        $key = $this->cacheKey($storeId, $customerId, $email, $sku, $pageCurrency, $features);
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->decode($cached);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        if ($this->cache->load($this->storeBackoffKey($storeId))
            || $this->cache->load($key . '_failed')
            || $this->overBudget($storeId, $customerId)
        ) {
            return self::EMPTY_RESULT;
        }

        $result = self::EMPTY_RESULT;
        $complete = true;
        $buyer = ['buyerEmail' => $email];
        $priced = $buyer + ['sku' => $sku, 'currency' => $pageCurrency];

        foreach ($features as $feature) {
            switch ($feature) {
                case Config::B2B_ACCOUNT_PRICE:
                    $route = StorefrontClient::ROUTE_WHOLESALE_PRICE;
                    $query = $priced + ['quantity' => '1'];
                    break;
                case Config::B2B_QUANTITY_BREAKS:
                    $route = StorefrontClient::ROUTE_QUANTITY_BREAKS;
                    $query = $priced;
                    break;
                case Config::B2B_ORDER_LIMITS:
                    $route = StorefrontClient::ROUTE_ORDER_LIMITS;
                    $query = $buyer + ['sku' => $sku];
                    break;
                case Config::B2B_BUYER_GROUP:
                    $route = StorefrontClient::ROUTE_BUYER_GROUP;
                    $query = $buyer;
                    break;
                default:
                    continue 2;
            }

            $answer = $this->client->get($route, $query, $storeId);
            if (!$answer['ok']) {
                $complete = false;
                if ($answer['status'] === 429) {
                    $this->cache->save(
                        '1',
                        $this->storeBackoffKey($storeId),
                        [self::CACHE_TAG],
                        self::THROTTLED_BACKOFF_TTL
                    );
                    break;
                }
                continue;
            }

            $data = $answer['data'] ?? null;
            switch ($feature) {
                case Config::B2B_ACCOUNT_PRICE:
                    $result['accountPrice'] = $this->mapper->accountPrice($data, $pageCurrency);
                    break;
                case Config::B2B_QUANTITY_BREAKS:
                    $result['quantityBreaks'] = $this->mapper->quantityBreaks($data, $pageCurrency);
                    break;
                case Config::B2B_ORDER_LIMITS:
                    $result['orderLimit'] = $this->mapper->orderLimit($data);
                    break;
                case Config::B2B_BUYER_GROUP:
                    $result['buyerGroup'] = $this->mapper->buyerGroup($data);
                    break;
            }
        }

        if ($complete) {
            $this->cache->save($this->json->serialize($result), $key, [self::CACHE_TAG], self::CACHE_TTL);
        } else {
            $this->cache->save('1', $key . '_failed', [self::CACHE_TAG], self::FAILURE_BACKOFF_TTL);
        }

        return $result;
    }

    /**
     * The cache id for one customer, product, currency and feature set.
     *
     * Public so the test can pin exactly what varies it. Hashed: the parts include an email
     * address, and a cache id is not a place for one.
     *
     * @param int $storeId
     * @param int $customerId
     * @param string $email
     * @param string $sku
     * @param string $pageCurrency
     * @param string[] $features
     * @return string
     */
    public function cacheKey(
        int $storeId,
        int $customerId,
        string $email,
        string $sku,
        string $pageCurrency,
        array $features
    ): string {
        return self::CACHE_PREFIX . hash(
            'sha256',
            implode("\n", [
                (string) $storeId,
                (string) $customerId,
                strtolower(trim($email)),
                trim($sku),
                strtoupper(trim($pageCurrency)),
                implode(',', $features),
            ])
        );
    }

    /**
     * Store-wide back-off marker id.
     *
     * @param int $storeId
     * @return string
     */
    private function storeBackoffKey(int $storeId): string
    {
        return self::CACHE_PREFIX . 'throttled_' . $storeId;
    }

    /**
     * Count one uncached lookup against the customer's per-minute budget.
     *
     * A fixed one-minute window in the cache. Not atomic across concurrent requests,
     * which at worst lets a few extra lookups through; the store-wide 429 back-off is the
     * hard stop.
     *
     * @param int $storeId
     * @param int $customerId
     * @return bool True when the budget is already spent.
     */
    private function overBudget(int $storeId, int $customerId): bool
    {
        $window = (int) floor(time() / 60);
        $key = self::CACHE_PREFIX . 'budget_' . hash('sha256', $storeId . '|' . $customerId) . '_' . $window;
        $used = (int) $this->cache->load($key);
        if ($used >= self::CUSTOMER_LOOKUP_BUDGET) {
            return true;
        }
        $this->cache->save((string) ($used + 1), $key, [self::CACHE_TAG], 120);

        return false;
    }

    /**
     * A cached result, or null when the entry is unreadable or not of the expected shape.
     *
     * @param string $cached
     * @return array|null
     */
    private function decode(string $cached): ?array
    {
        try {
            $decoded = $this->json->unserialize($cached);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        foreach (array_keys(self::EMPTY_RESULT) as $field) {
            if (!array_key_exists($field, $decoded)) {
                return null;
            }
        }

        $result = array_intersect_key($decoded, self::EMPTY_RESULT);

        // JSON has no float type: 9.0 comes back as 9. Restore the types a fresh answer has,
        // so a cached and an uncached answer are identical.
        // A price that is not a number is a damaged entry: refetch rather than show 0.
        if (is_array($result['accountPrice'])) {
            if (!is_numeric($result['accountPrice']['unitPrice'] ?? null)) {
                return null;
            }
            $result['accountPrice']['unitPrice'] = (float) $result['accountPrice']['unitPrice'];
        }
        if (is_array($result['quantityBreaks'])) {
            if (!is_array($result['quantityBreaks']['rows'] ?? null)) {
                return null;
            }
            foreach ($result['quantityBreaks']['rows'] as $i => $row) {
                if (!is_numeric($row['unitPrice'] ?? null) || !is_numeric($row['minQty'] ?? null)) {
                    return null;
                }
                $result['quantityBreaks']['rows'][$i] = [
                    'minQty' => (int) $row['minQty'],
                    'unitPrice' => (float) $row['unitPrice'],
                ];
            }
        }

        return $result;
    }
}
