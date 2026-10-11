<?php
/**
 * The signed-in customer's TackQuote buyer group (and tax exemption), for the buyer-group
 * RULES: hidden categories, payment and shipping restrictions, shipping discounts, tax
 * exemption and the customer-group mirror.
 *
 * WIRE CONTRACT: the TackQuote server is the authority:
 *   GET /v1/storefront/v1/buyer-group?buyerEmail=<email>
 *   - StorefrontV1Controller::buyerGroup (tack apps/api/src/modules/storefront-api/
 *     storefront-v1.controller.ts:197-205): `@Throttle` 60/min, `Cache-Control: private,
 *     no-store`, X-Api-Key only (Model\Api\StorefrontClient sends exactly that).
 *   - Answer: BuyerGroupResult = {status: anonymous} | {status: unlinked, reason} |
 *     {status: none, taxExempt?} | {status: grouped, name, code, taxExempt?}
 *     (storefront-core/storefront-results.ts:82-86), built by StorefrontB2bCore::buyerGroup
 *     (storefront-core/storefront-b2b.core.ts:71-97).
 *   - `taxExempt` is set ONLY for a buyer reached through a platform link (`match.basis ===
 *     'platform'`, storefront-b2b.core.ts:88-92; storefront-buyer.ts:274-320), never by email
 *     alone (audit H-2: an email is a claim, an exemption spends the government's tax). So the
 *     customer is named the way Model\Forms\AccountIdentity names it for the net-terms and
 *     wholesale routes: `buyerEmail`, plus, when the server lists the `customerIdentity`
 *     capability and this install has its id, `buyerExternalId` = the Magento customer id with
 *     the headers `X-TackQuote-Platform: magento` and `X-TackQuote-Store-Id: <install id>`
 *     (tack api-key-storefront-identity.resolver.ts; storefront-buyer.ts
 *     `resolveAssertedBuyerRef()`, the `magento_customer` namespace). Without them the server
 *     can only match by email and never answers `taxExempt: true`.
 *
 * The same route the B2B display badge reads (Model\B2bDisplay\DataProvider, PR #1), with
 * the same 60-second freshness. That cache is keyed per product for the display, so the rules
 * keep their own per-customer entry here rather than one per product page.
 *
 * MAPPING (WooCommerce `Tack_B2B_Notices::buyer_group()` / `buyer_group_status()`):
 *   grouped with a code      -> GROUPED (code upper-cased)
 *   grouped without a code   -> NONE    (it cannot match a rule)
 *   none, unlinked           -> NONE    (a real answer, not an outage)
 *   anonymous                -> ANONYMOUS
 *   anything else, a non-2xx (429 included), a transport error, no API key -> UNAVAILABLE
 *
 * CACHING. A definite answer is kept CACHE_TTL (60 s) per store, customer id and email; a
 * failure FAIL_TTL (30 s) so an outage is not retried on every request; a 429 pauses every
 * lookup for the store for THROTTLED_TTL (60 s), because the throttle is the store's, not the
 * shopper's. The cache id is a SHA-256, so no email address appears in it. A per-request memo
 * answers repeat questions without touching the cache.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Forms\IdentityResolver;

class BuyerGroupResolver
{
    public const CACHE_TAG = 'TACKQUOTE_BUYER_GROUP';
    private const CACHE_PREFIX = 'tackquote_bg_';

    /** Seconds a definite answer is reused (the B2B display's DataProvider::CACHE_TTL). */
    public const CACHE_TTL = 60;

    /** Seconds a failed lookup is remembered before TackQuote is asked again. */
    public const FAIL_TTL = 30;

    /** Seconds the whole store stops asking after TackQuote answered 429. */
    public const THROTTLED_TTL = 60;

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
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var array<string, BuyerGroupAnswer>
     */
    private $memo = [];

    /**
     * @param StorefrontClient $client
     * @param CacheInterface $cache
     * @param Json $json
     * @param IdentityResolver $identities
     */
    public function __construct(
        StorefrontClient $client,
        CacheInterface $cache,
        Json $json,
        IdentityResolver $identities
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
        $this->identities = $identities;
    }

    /**
     * @param int $storeId
     * @param int $customerId
     * @param string $email The customer's account email.
     * @return BuyerGroupAnswer
     */
    public function resolve(int $storeId, int $customerId, string $email): BuyerGroupAnswer
    {
        $email = strtolower(trim($email));
        $who = $this->identities->forCustomer($customerId, $email, $storeId);
        if (!$who->isSignedIn()) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::ANONYMOUS);
        }

        $cacheId = self::cacheId($storeId, $customerId, $email, $who->carriesCustomerId());
        if (isset($this->memo[$cacheId])) {
            return $this->memo[$cacheId];
        }

        $cached = $this->decode($this->cache->load($cacheId));
        if ($cached !== null) {
            return $this->memo[$cacheId] = $cached;
        }

        if ($this->cache->load(self::storeBackoffId($storeId))) {
            return $this->memo[$cacheId] = new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE);
        }

        $answer = $this->client->get(
            StorefrontClient::ROUTE_BUYER_GROUP,
            $who->query(),
            $storeId,
            $who->headers()
        );

        if (empty($answer['ok'])) {
            if ((int) ($answer['status'] ?? 0) === 429) {
                $this->cache->save('1', self::storeBackoffId($storeId), [self::CACHE_TAG], self::THROTTLED_TTL);
            }
            $result = new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE);
        } else {
            $result = self::map($answer['data'] ?? null);
        }

        $this->cache->save(
            $this->encode($result),
            $cacheId,
            [self::CACHE_TAG],
            $result->isUnknown() ? self::FAIL_TTL : self::CACHE_TTL
        );

        return $this->memo[$cacheId] = $result;
    }

    /**
     * A decoded BuyerGroupResult -> answer. See the class docblock for the table.
     *
     * @param mixed $data
     * @return BuyerGroupAnswer
     */
    public static function map($data): BuyerGroupAnswer
    {
        if (!is_array($data) || !is_string($data['status'] ?? null)) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE);
        }
        // Only the boolean true exempts: "true", 1, a missing field and false do not.
        $taxExempt = ($data['taxExempt'] ?? null) === true;

        switch ($data['status']) {
            case 'grouped':
                $code = is_string($data['code'] ?? null) ? $data['code'] : '';

                return new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, $code, $taxExempt);
            case 'none':
                return new BuyerGroupAnswer(BuyerGroupAnswer::NONE, '', $taxExempt);
            case 'unlinked':
                return new BuyerGroupAnswer(BuyerGroupAnswer::NONE);
            case 'anonymous':
                return new BuyerGroupAnswer(BuyerGroupAnswer::ANONYMOUS);
            default:
                return new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE);
        }
    }

    /**
     * The cache id for one customer. A hash, so the email address never appears in it. Whether
     * the customer id was sent is part of it: an answer read by email alone carries no
     * exemption and must not stand in for one read with the platform link.
     *
     * @param int $storeId
     * @param int $customerId
     * @param string $email Already trimmed and lower-cased.
     * @param bool $withCustomerId
     * @return string
     */
    public static function cacheId(int $storeId, int $customerId, string $email, bool $withCustomerId = false): string
    {
        return self::CACHE_PREFIX . hash(
            'sha256',
            'buyer-group|' . $storeId . '|' . $customerId . '|' . $email . '|' . ($withCustomerId ? 'id' : 'email')
        );
    }

    /**
     * @param int $storeId
     * @return string
     */
    private static function storeBackoffId(int $storeId): string
    {
        return self::CACHE_PREFIX . 'throttled_' . $storeId;
    }

    /**
     * @param BuyerGroupAnswer $answer
     * @return string
     */
    private function encode(BuyerGroupAnswer $answer): string
    {
        return (string) $this->json->serialize([
            's' => $answer->getStatus(),
            'c' => $answer->getCode(),
            't' => $answer->isTaxExempt(),
        ]);
    }

    /**
     * A cached answer, or null when absent or unreadable.
     *
     * @param mixed $cached
     * @return BuyerGroupAnswer|null
     */
    private function decode($cached): ?BuyerGroupAnswer
    {
        if (!is_string($cached) || $cached === '') {
            return null;
        }
        try {
            $data = $this->json->unserialize($cached);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($data) || !is_string($data['s'] ?? null) || !is_string($data['c'] ?? null)) {
            return null;
        }

        return new BuyerGroupAnswer($data['s'], $data['c'], ($data['t'] ?? null) === true);
    }
}
