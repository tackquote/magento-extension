<?php
/**
 * Client for the TackQuote account routes behind the Wholesale account and Net terms tabs
 * and the "Pay on net terms" checkout method.
 *
 * WIRE CONTRACT: tack StorefrontV1Controller and StorefrontV1UploadsController
 * (apps/api/src/modules/storefront-api/). Every route is shared `/storefront/v1/*`; none is
 * WooCommerce-labelled, so this module needs no alias:
 *
 *   GET  wholesale-signup[/<slug>]                     form definition
 *   POST wholesale-upload?form=&field=&name=           one file, raw bytes  (buyers:write)
 *   POST wholesale-signup[/<slug>]  {values}           the application      (buyers:write)
 *   GET  wholesale-application                         none|pending|approved|rejected
 *   GET  net-terms                                     NetTermsResult
 *   POST credit-application  SubmitCreditApplicationDto                     (buyers:write)
 *
 * AUTHENTICATION: the store's API key in `X-Api-Key` and nothing in `Authorization` (the
 * route refuses two storefront credentials). Every call gets a FRESH Curl from the factory,
 * so no header from another call in the same PHP request can ride along. The buyer is
 * asserted by Model\Forms\AccountIdentity (email, and the Magento customer id where the
 * server namespaces it).
 *
 * FAIL CLOSED: anything but a 2xx with a JSON object is `ok: false`. TackQuote's own refusal
 * text is returned as `message` (it names the rule, never another shopper's data); callers
 * decide whether to show it.
 *
 * LOGGING: the route and the HTTP status only. Never the key, the URL (its query carries
 * the customer's email), a body, or a file.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class AccountApiClient
{
    private const PREFIX = '/storefront/v1/';

    /** A page or a checkout is waiting on a read. */
    private const READ_TIMEOUT_SECONDS = 5;

    /** A shopper is waiting on a write; an application or a 5 MB file. */
    private const WRITE_TIMEOUT_SECONDS = 15;

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
     * The seller's wholesale form: `{name, description, fields, successMessage, slug}`.
     *
     * Read without a buyer: the definition is the same for every shopper.
     *
     * @param string $slug '' for the seller's default form.
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function getWholesaleForm(string $slug, ?int $storeId = null): array
    {
        return $this->send('GET', $this->signupPath($slug), [], [], null, $storeId);
    }

    /**
     * Stream one application file. Answers `{uploadId, filename, size, mimeType}`.
     *
     * @param string $bytes
     * @param string $name Sanitised file name.
     * @param string $slug Form slug, or '' for the default form.
     * @param string $fieldKey The file field's key.
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function uploadWholesaleFile(
        string $bytes,
        string $name,
        string $slug,
        string $fieldKey,
        AccountIdentity $who,
        ?int $storeId = null
    ): array {
        if ($bytes === '' || !$who->isSignedIn()) {
            return ['ok' => false, 'status' => 0];
        }
        $query = array_merge(
            array_filter(['form' => $slug, 'field' => $fieldKey, 'name' => $name], 'strlen'),
            $who->query()
        );

        return $this->send(
            'POST',
            'wholesale-upload',
            $query,
            array_merge($who->headers(), ['Content-Type' => 'application/octet-stream']),
            $bytes,
            $storeId
        );
    }

    /**
     * Submit a wholesale application. Answers `{id, status, message}`.
     *
     * @param string $slug
     * @param array $values Answers keyed by field key; a file field's value is `{uploadId}`.
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function submitWholesale(string $slug, array $values, AccountIdentity $who, ?int $storeId = null): array
    {
        if (!$who->isSignedIn()) {
            return ['ok' => false, 'status' => 0];
        }

        return $this->send(
            'POST',
            $this->signupPath($slug),
            $who->query(),
            array_merge($who->headers(), ['Content-Type' => 'application/json']),
            // An empty answer set must still serialise as an object, as the DTO expects.
            $this->json->serialize(['values' => (object) $values]),
            $storeId
        );
    }

    /**
     * The customer's own wholesale application state.
     *
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function getWholesaleApplication(AccountIdentity $who, ?int $storeId = null): array
    {
        if (!$who->isSignedIn()) {
            return ['ok' => false, 'status' => 0];
        }

        return $this->send('GET', 'wholesale-application', $who->query(), $who->headers(), null, $storeId);
    }

    /**
     * The customer's own net-terms standing (`NetTermsResult`).
     *
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function getNetTerms(AccountIdentity $who, ?int $storeId = null): array
    {
        if (!$who->isSignedIn()) {
            return ['ok' => false, 'status' => 0];
        }

        return $this->send('GET', 'net-terms', $who->query(), $who->headers(), null, $storeId);
    }

    /**
     * File a net-terms application. Answers `{status: received|already_pending, ...}`.
     *
     * The applicant is the ASSERTED identity in the query, never a body field: the DTO
     * refuses unknown keys, and letting the body choose would let one shopper apply as
     * another.
     *
     * @param array $payload Built by CreditApplicationBuilder.
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    public function submitCreditApplication(array $payload, AccountIdentity $who, ?int $storeId = null): array
    {
        if (!$who->isSignedIn()) {
            return ['ok' => false, 'status' => 0];
        }

        return $this->send(
            'POST',
            'credit-application',
            $who->query(),
            array_merge($who->headers(), ['Content-Type' => 'application/json']),
            $this->json->serialize($payload),
            $storeId
        );
    }

    /**
     * `wholesale-signup` or `wholesale-signup/<slug>`.
     *
     * @param string $slug
     * @return string
     */
    private function signupPath(string $slug): string
    {
        return $slug !== '' ? 'wholesale-signup/' . rawurlencode($slug) : 'wholesale-signup';
    }

    /**
     * One request.
     *
     * @param string $method GET or POST.
     * @param string $route Path under /storefront/v1/.
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param string|null $body
     * @param int|null $storeId
     * @return array{ok: bool, status: int, data?: array, message?: string}
     */
    private function send(
        string $method,
        string $route,
        array $query,
        array $headers,
        ?string $body,
        ?int $storeId
    ): array {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return ['ok' => false, 'status' => 0];
        }

        $url = $this->config->getApiBaseUrl($storeId) . self::PREFIX . $route;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $label = strtok($route, '/');

        $curl = $this->curlFactory->create();
        $curl->setTimeout($method === 'GET' ? self::READ_TIMEOUT_SECONDS : self::WRITE_TIMEOUT_SECONDS);
        // setHeaders() REPLACES the set: exactly these go out, and never Authorization.
        $curl->setHeaders(array_merge(
            $headers,
            [
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
                'User-Agent' => 'TackQuote-Magento2',
            ]
        ));

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, (string) $body);
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('TackQuote storefront %s request failed to complete.', $label));

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
            $this->logger->warning(sprintf('TackQuote storefront %s answered HTTP %d.', $label, $status));
            $out = ['ok' => false, 'status' => $status];
            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                $out['message'] = mb_substr(trim(strip_tags($decoded['message'])), 0, 300);
            }

            return $out;
        }

        return ['ok' => true, 'status' => $status, 'data' => $decoded];
    }
}
