<?php
/**
 * Read-only client for TackQuote's platform-neutral storefront API, `/v1/storefront/v1/*`.
 *
 * WIRE CONTRACT — the authority is the TackQuote server, not this file and not the
 * WooCommerce plugin: `StorefrontV1Controller`
 * (tack apps/api/src/modules/storefront-api/storefront-v1.controller.ts) and the answer
 * unions in apps/api/src/modules/storefront-core/storefront-results.ts. The four routes
 * this module reads, all GET, all answering `Cache-Control: private, no-store`:
 *
 *   wholesale-price  ?sku=&quantity=&buyerEmail=&currency=  -> WholesalePriceResult
 *   quantity-breaks  ?sku=&buyerEmail=&currency=            -> QuantityBreakResult
 *   order-limits     ?sku=&buyerEmail=                      -> OrderLimitsNoticeResult
 *   buyer-group      ?buyerEmail=                           -> BuyerGroupResult
 *
 * AUTHENTICATION — the store's TackQuote API key in `X-Api-Key` and NOTHING in
 * `Authorization`. On these routes `Authorization` is a platform token (Wix), and
 * `StorefrontIdentityGuard` refuses a request that carries two storefront credentials
 * (ApiKeyStorefrontIdentityResolver, api-key-storefront-identity.resolver.ts: "On this
 * route the key must be in X-Api-Key"). Model\Api\Client sends the key in both headers
 * for the `/integrations/magento/*` routes, which is why this is a separate client: one
 * shared Curl instance would carry that Authorization header into these calls. Every
 * request here gets a FRESH Curl from the factory, so no header can leak in from another
 * call made earlier in the same PHP request. No scope is required on any of the four
 * (none carries @RequireApiScope); the key's plan is still metered by PlanGuard.
 *
 * THE BUYER IS ASSERTED — `buyerEmail` is this store's word for who is signed in, vouched
 * for by the secret key. That is the trust model every plugin route has. No email means
 * the server answers `anonymous`, so callers only ask for a signed-in customer.
 * `buyerExternalId` is deliberately NOT sent: the server parses it as a WordPress user id
 * and namespaces it under the tenant's WooCommerce connection
 * (storefront-core/storefront-buyer.ts), so a Magento customer id there would be read as
 * somebody else's id. A Magento namespace is a two-repo follow-up.
 *
 * FAIL CLOSED — anything but a 2xx carrying a JSON object is `ok: false`. The caller then
 * shows no B2B data and the product keeps Magento's own price. Nothing is ever invented.
 *
 * LOGGING — the route and the HTTP status only. Never the API key, never the URL (its
 * query carries the customer's email address), never the response body.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class StorefrontClient
{
    public const ROUTE_WHOLESALE_PRICE = 'wholesale-price';
    public const ROUTE_QUANTITY_BREAKS = 'quantity-breaks';
    public const ROUTE_ORDER_LIMITS = 'order-limits';
    public const ROUTE_BUYER_GROUP = 'buyer-group';

    /** The only routes this client will call. Anything else is refused before any I/O. */
    private const ROUTES = [
        self::ROUTE_WHOLESALE_PRICE,
        self::ROUTE_QUANTITY_BREAKS,
        self::ROUTE_ORDER_LIMITS,
        self::ROUTE_BUYER_GROUP,
    ];

    private const PATH_PREFIX = '/storefront/v1/';

    /**
     * A product page is waiting on this (through the browser's background request). Past
     * a couple of seconds the right answer is "show the store's own price", not "wait".
     * The WooCommerce plugin uses 5s for the same reads; this module's reads use 3s.
     */
    private const TIMEOUT_SECONDS = 3;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * One `/storefront/v1/{route}` read.
     *
     * @param string $route One of the ROUTE_* constants.
     * @param array<string, string> $query Query parameters (sku, quantity, buyerEmail, currency).
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array}
     */
    public function get(string $route, array $query, ?int $storeId = null): array
    {
        if (!in_array($route, self::ROUTES, true)) {
            return ['ok' => false, 'status' => 0];
        }

        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0];
        }

        $query = array_filter(
            $query,
            static function ($value): bool {
                return is_string($value) && $value !== '';
            }
        );
        $url = $this->config->getApiBaseUrl($storeId) . self::PATH_PREFIX . $route;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        // setHeaders() REPLACES the header set, so exactly these go out. Authorization is
        // absent on purpose: see the class docblock.
        $curl->setHeaders([
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'TackQuote-Magento2',
        ]);

        try {
            $curl->get($url);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('TackQuote storefront %s request failed to complete.', $route));

            return ['ok' => false, 'status' => 0];
        }

        $status = (int) $curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(sprintf('TackQuote storefront %s answered HTTP %d.', $route, $status));

            return ['ok' => false, 'status' => $status];
        }

        $decoded = null;
        $body = (string) $curl->getBody();
        if ($body !== '') {
            try {
                $decoded = $this->json->unserialize($body);
            } catch (\Exception $e) {
                $decoded = null;
            }
        }

        if (!is_array($decoded)) {
            $this->logger->warning(sprintf('TackQuote storefront %s answered a body that is not JSON.', $route));

            return ['ok' => false, 'status' => $status];
        }

        return ['ok' => true, 'status' => $status, 'data' => $decoded];
    }
}
