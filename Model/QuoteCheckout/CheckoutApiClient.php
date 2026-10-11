<?php
/**
 * The module's two server-to-server calls for the accepted-quote checkout link.
 *
 * WIRE CONTRACT: the authority is the TackQuote server, `MagentoQuoteCheckoutController` in
 * tack apps/api/src/modules/integrations/magento/magento-quote-checkout.controller.ts
 * (docs/integrations/MAGENTO.md, "Accepted quote checkout link"):
 *
 *   GET  /integrations/magento/quote-checkout/<token>
 *        200 the cart (Model\QuoteCheckout\PayloadReader)
 *        404 unknown token, 410 expired / used / superseded / quote no longer open;
 *        both carry `portalUrl`, the buyer portal's public sign-in page
 *   POST /integrations/magento/quote-checkout/complete
 *        {quoteRef, orderId, incrementId, state?, paymentMethod?}
 *        200 {linked, quoteNumber, orderId, settled}, idempotent; 404 unknown reference;
 *        409 the checkout is already linked to another order
 *
 * AUTHENTICATION: the store's TackQuote API key in `X-Api-Key` and NOTHING in `Authorization`,
 * with a FRESH Curl per call (setHeaders() replaces the header set), the rule
 * Model\Api\StorefrontClient documents: no header can leak in from another call made earlier
 * in the same PHP request. The route needs the `quotes:write` scope, which a Connect-minted key
 * carries.
 *
 * A TOKEN IS SPENT ON THE FIRST EXCHANGE THAT PASSES TackQuote's CHECKS, so the exchange is
 * made once and never retried: a retry could only ever answer "already used".
 *
 * LOGGING: the route and the HTTP status only. Never the key, the token (it is in the URL),
 * the buyer's email, or the body.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class CheckoutApiClient
{
    /** 32 random bytes, base64url: the only token shape TackQuote mints. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public const PATH_EXCHANGE = '/integrations/magento/quote-checkout/';
    public const PATH_COMPLETE = '/integrations/magento/quote-checkout/complete';

    /** The buyer is waiting on the landing page. */
    private const EXCHANGE_TIMEOUT_SECONDS = 5;

    /** The buyer is waiting on "Place order"; the order pull reports a lost completion later. */
    private const COMPLETE_TIMEOUT_SECONDS = 3;

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
     * Exchange a checkout token for the accepted quote's cart. Made once; never retried.
     *
     * @param string $token
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, portalUrl?: string}
     */
    public function exchange(string $token, ?int $storeId = null): array
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return ['ok' => false, 'status' => 0];
        }
        $result = $this->send('GET', self::PATH_EXCHANGE . $token, null, self::EXCHANGE_TIMEOUT_SECONDS, $storeId);
        if (!$result['ok'] && in_array($result['status'], [404, 410], true)) {
            $url = $result['data']['portalUrl'] ?? null;
            if (is_string($url) && preg_match('#^https://[^\s"<>]+$#', $url) === 1) {
                $result['portalUrl'] = $url;
            }
            unset($result['data']);
        }

        return $result;
    }

    /**
     * Report the order a quoted cart became.
     *
     * @param array{quoteRef:string,orderId:string,incrementId:string,state?:string,paymentMethod?:string} $report
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array}
     */
    public function complete(array $report, ?int $storeId = null): array
    {
        return $this->send('POST', self::PATH_COMPLETE, $report, self::COMPLETE_TIMEOUT_SECONDS, $storeId);
    }

    /**
     * One request with the key in X-Api-Key only.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param int $timeout
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array}
     */
    private function send(string $method, string $path, ?array $body, int $timeout, ?int $storeId): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0];
        }
        $route = $method === 'GET' ? 'quote-checkout exchange' : 'quote-checkout complete';

        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        // setHeaders() REPLACES the header set: exactly these, and no Authorization.
        $headers = [
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'TackQuote-Magento2',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $curl->setHeaders($headers);

        $url = $this->config->getApiBaseUrl($storeId) . $path;
        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, (string) $this->json->serialize($body ?? []));
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('TackQuote %s request failed to complete.', $route));

            return ['ok' => false, 'status' => 0];
        }

        $status = (int) $curl->getStatus();
        $decoded = null;
        $raw = (string) $curl->getBody();
        if ($raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
            } catch (\Exception $e) {
                $decoded = null;
            }
        }

        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $this->logger->warning(sprintf('TackQuote %s answered HTTP %d.', $route, $status));

            return ['ok' => false, 'status' => $status, 'data' => is_array($decoded) ? $decoded : []];
        }

        return ['ok' => true, 'status' => $status, 'data' => $decoded];
    }
}
