<?php
/**
 * Has TackQuote approved this signed-in customer's wholesale application?
 *
 * Drives quote-only mode's "everyone except approved wholesale accounts" scope
 * (QuoteOnlyRules::SCOPE_UNAPPROVED).
 *
 * WIRE CONTRACT — the TackQuote server is the authority:
 *   GET /v1/storefront/v1/price-access?buyerEmail=<email>
 *   - StorefrontV1Controller::priceAccess (tack
 *     apps/api/src/modules/storefront-api/storefront-v1.controller.ts:211-220): throttled
 *     60/min, `Cache-Control: private, no-store`, no @RequireApiScope.
 *   - The key goes in `X-Api-Key` only; ApiKeyStorefrontIdentityResolver reads `buyerEmail`
 *     from the query, trims and lower-cases it, and an empty one is an anonymous identity
 *     (api-key-storefront-identity.resolver.ts:98-121). Model\Api\StorefrontClient sends
 *     exactly that.
 *   - Answer: PriceAccessResult = {status: anonymous} | {status: unlinked, reason} |
 *     {status: linked, wholesaleApproved: boolean} (storefront-core/storefront-results.ts:
 *     96-99). `wholesaleApproved` is true only when the buyer the email resolves to has a
 *     wholesale application in status `approved` (StorefrontB2bCore::priceAccess,
 *     storefront-core/storefront-b2b.core.ts:107-125); a buyer linked any other way is
 *     `linked` with `wholesaleApproved: false`.
 *
 * FAILS CLOSED. No email, no API key, a transport error, a non-2xx (429 included), a body
 * that is not JSON, or a shape this module does not recognise all answer FALSE — not
 * approved, so the visitor gets the quote-only storefront. That is the opposite of the B2B
 * display, which fails open to Magento's own price, and deliberately so: the scope exists to
 * keep the cart from buyers the seller has not approved, and opening it during an outage
 * cannot be undone. An approved buyer caught by an outage can still request a quote. The
 * WooCommerce plugin's 1.11.0 price gate makes the same choice (Tack_Price_Access).
 *
 * CACHING. A definite answer is kept for CACHE_TTL (5 minutes — approval is a one-off
 * seller action, so that is the longest an approved buyer waits); a failure for FAIL_TTL
 * (1 minute) so an outage is not retried on every page view. Both match the WooCommerce
 * plugin. The cache id is a SHA-256 of store, customer id and email, so no address appears
 * in a Redis key or a var/cache file name. A per-request memo answers repeat questions
 * within one request without touching the cache.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

use Magento\Framework\App\CacheInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class WholesaleApproval
{
    public const CACHE_TAG = 'TACKQUOTE_PRICE_ACCESS';
    private const CACHE_PREFIX = 'tackquote_pa_';

    /** Seconds a definite answer (approved or not) is reused. */
    public const CACHE_TTL = 300;

    /** Seconds a failed lookup is remembered before TackQuote is asked again. */
    public const FAIL_TTL = 60;

    private const APPROVED = 'approved';
    private const NOT_APPROVED = 'not_approved';
    private const UNAVAILABLE = 'unavailable';

    /**
     * @var StorefrontClient
     */
    private $client;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var array<string, bool>
     */
    private $memo = [];

    /**
     * @param StorefrontClient $client
     * @param CacheInterface $cache
     */
    public function __construct(StorefrontClient $client, CacheInterface $cache)
    {
        $this->client = $client;
        $this->cache = $cache;
    }

    /**
     * Whether TackQuote has approved this customer's wholesale account.
     *
     * @param string $email The signed-in customer's account email ('' asks nothing).
     * @param int|null $customerId
     * @param int|null $storeId
     * @return bool
     */
    public function isApproved(string $email, ?int $customerId, ?int $storeId = null): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $cacheId = $this->cacheId($email, $customerId, $storeId);
        if (array_key_exists($cacheId, $this->memo)) {
            return $this->memo[$cacheId];
        }

        $cached = $this->cache->load($cacheId);
        if ($cached === self::APPROVED || $cached === self::NOT_APPROVED || $cached === self::UNAVAILABLE) {
            return $this->memo[$cacheId] = ($cached === self::APPROVED);
        }

        $answer = $this->client->get(
            StorefrontClient::ROUTE_PRICE_ACCESS,
            ['buyerEmail' => $email],
            $storeId
        );
        $data = $answer['data'] ?? null;

        if (empty($answer['ok']) || !is_array($data) || !is_string($data['status'] ?? null)) {
            $this->cache->save(self::UNAVAILABLE, $cacheId, [self::CACHE_TAG], self::FAIL_TTL);

            return $this->memo[$cacheId] = false;
        }

        $approved = QuoteOnlyRules::isApprovedAnswer($data);
        $this->cache->save(
            $approved ? self::APPROVED : self::NOT_APPROVED,
            $cacheId,
            [self::CACHE_TAG],
            self::CACHE_TTL
        );

        return $this->memo[$cacheId] = $approved;
    }

    /**
     * The cache id for one customer. A hash, so the email address never appears in it.
     *
     * @param string $email Already trimmed and lower-cased.
     * @param int|null $customerId
     * @param int|null $storeId
     * @return string
     */
    public function cacheId(string $email, ?int $customerId, ?int $storeId): string
    {
        return self::CACHE_PREFIX . hash(
            'sha256',
            'price-access|' . (int) $storeId . '|' . (int) $customerId . '|' . $email
        );
    }
}
