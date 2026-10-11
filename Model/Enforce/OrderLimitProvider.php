<?php
/**
 * TackQuote's order-limit answer for one signed-in customer and one SKU, cached briefly.
 *
 * `GET /storefront/v1/order-limits?sku=&buyerEmail=` (StorefrontV1Controller::orderLimits,
 * tack apps/api/src/modules/storefront-api/storefront-v1.controller.ts:180-196, answering
 * OrderLimitsNoticeResult from storefront-core/storefront-results.ts:66-69): the same read, with
 * the same parameters, as the product page's notice (Model\B2bDisplay\DataProvider), so the cart
 * enforces exactly what the page states. Each limit is `{limitType, sku, min, max, currency,
 * message}` (order-limits/order-limits.service.ts:46-53). There is NO step / increment field on
 * this wire, so a quantity step is not enforced; TackQuote would have to send one first.
 *
 * CACHE — keyed (SHA-256) by store, customer id, email and SKU for CACHE_TTL, like the display.
 * Every `/storefront/v1` route is throttled at 60 requests per minute; the store-wide 429
 * back-off is the display's own marker, so a throttled store stops asking from both places.
 *
 * FAIL OPEN — any failure returns null and nothing is refused, the WooCommerce plugin's rule
 * for the same check (Tack_B2B_Notices::order_limit_violations(): "No answer: nothing is
 * blocked"). A limit is a policy the seller can still apply on the TackQuote side; refusing
 * every order while TackQuote is unreachable would stop the store selling.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\B2bDisplay\DataProvider;

class OrderLimitProvider
{
    /** Seconds one SKU's answer is reused for the same customer. */
    public const CACHE_TTL = 60;

    /** Seconds before a failed lookup for the same customer and SKU is retried. */
    public const FAILURE_BACKOFF_TTL = 30;

    private const CACHE_PREFIX = 'tackquote_enforce_limits_';

    /** The display's store-wide 429 marker (DataProvider, `tackquote_b2b_throttled_<store>`). */
    private const V1_THROTTLED_PREFIX = 'tackquote_b2b_throttled_';

    /**
     * @var StorefrontClient
     */
    private $client;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var array<string, array|false>
     */
    private $memo = [];

    /**
     * @param StorefrontClient $client
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(StorefrontClient $client, CacheInterface $cache, Json $json)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * The decoded answer for this SKU, or null when there is none to enforce.
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     * @param string $sku
     * @return array|null
     */
    public function forSku(array $buyer, string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $storeId = (int) $buyer['storeId'];
        $key = $this->cacheKey($buyer, $sku);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key] === false ? null : $this->memo[$key];
        }

        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->json->unserialize($cached);
            } catch (\Exception $e) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $this->memo[$key] = $decoded;

                return $decoded;
            }
        }

        if ($this->cache->load(self::V1_THROTTLED_PREFIX . $storeId) || $this->cache->load($key . '_failed')) {
            $this->memo[$key] = false;

            return null;
        }

        $answer = $this->client->get(
            StorefrontClient::ROUTE_ORDER_LIMITS,
            ['buyerEmail' => $buyer['email'], 'sku' => $sku],
            $storeId
        );
        if (!$answer['ok'] || !is_array($answer['data'] ?? null)) {
            if ((int) $answer['status'] === 429) {
                $this->cache->save(
                    '1',
                    self::V1_THROTTLED_PREFIX . $storeId,
                    [DataProvider::CACHE_TAG],
                    DataProvider::THROTTLED_BACKOFF_TTL
                );
            }
            $this->cache->save('1', $key . '_failed', [DataProvider::CACHE_TAG], self::FAILURE_BACKOFF_TTL);
            $this->memo[$key] = false;

            return null;
        }

        $data = $answer['data'];
        $this->cache->save((string) $this->json->serialize($data), $key, [DataProvider::CACHE_TAG], self::CACHE_TTL);
        $this->memo[$key] = $data;

        return $data;
    }

    /**
     * Public so the test can pin what varies it; hashed because the parts include an email.
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     * @param string $sku
     * @return string
     */
    public function cacheKey(array $buyer, string $sku): string
    {
        return self::CACHE_PREFIX . hash(
            'sha256',
            implode("\n", [
                (string) (int) $buyer['storeId'],
                (string) (int) $buyer['customerId'],
                strtolower(trim((string) $buyer['email'])),
                trim($sku),
            ])
        );
    }
}
