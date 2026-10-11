<?php
/**
 * The four TackQuote calls of "Connect with TackQuote", server to server.
 *
 *   POST /storefront-connect/magento/requests   no key (there is none yet)
 *   POST /storefront-connect/magento/exchange   no key; code + PKCE verifier
 *   GET  /integrations/magento/ping             the minted key, to test it
 *   POST /integrations/magento/plugin-key/revoke the saved key, revokes itself
 *
 * The server half is tack `apps/api/src/modules/integrations/storefront-connect/`
 * and `magento/magento-plugin.controller.ts`. A fresh Curl client per call
 * (CurlFactory), so no header of one call (the key) can leak into another.
 *
 * Never logs a body: the exchange answer carries the plaintext API key.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class ConnectApi
{
    public const PATH_REQUESTS = '/storefront-connect/magento/requests';
    public const PATH_EXCHANGE = '/storefront-connect/magento/exchange';
    public const PATH_PING = '/integrations/magento/ping';
    public const PATH_REVOKE = '/integrations/magento/plugin-key/revoke';

    /** An admin is waiting on every one of these calls. */
    private const TIMEOUT_SECONDS = 15;

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
    public function __construct(Config $config, CurlFactory $curlFactory, Json $json, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Open a connect request.
     *
     * @param array $body
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    public function createRequest(array $body): array
    {
        return $this->call('POST', self::PATH_REQUESTS, $body, '');
    }

    /**
     * Redeem a one-time code for the API key.
     *
     * @param array $body {code, codeVerifier, siteUrl}
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    public function exchange(array $body): array
    {
        return $this->call('POST', self::PATH_EXCHANGE, $body, '');
    }

    /**
     * Test a key that is not saved yet (the config cache may still hold the old one).
     *
     * @param string $apiKey
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    public function ping(string $apiKey): array
    {
        return $this->call('GET', self::PATH_PING, null, $apiKey);
    }

    /**
     * Revoke the key presented, in TackQuote. The server revokes only that key.
     *
     * @param string $apiKey
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    public function revoke(string $apiKey): array
    {
        return $this->call('POST', self::PATH_REVOKE, [], $apiKey);
    }

    /**
     * One request. `status` 0 means TackQuote could not be reached.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param string $apiKey '' for the unauthenticated connect calls.
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    private function call(string $method, string $path, ?array $body, string $apiKey): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('User-Agent', 'TackQuote-Magento2/connect');
        if ($apiKey !== '') {
            $curl->addHeader('X-Api-Key', $apiKey);
        }
        $url = $this->config->getApiBaseUrl() . $path;

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, $this->json->serialize($body ?? []));
            }
        } catch (\Exception $e) {
            $this->logger->warning('TackQuote Connect: request failed: ' . $e->getMessage());
            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => ''];
        }

        $status = (int) $curl->getStatus();
        $raw = (string) $curl->getBody();
        $data = [];
        if ($raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
                $data = is_array($decoded) ? $decoded : [];
            } catch (\Exception $e) {
                $data = [];
            }
        }
        if ($status < 200 || $status >= 300) {
            $message = isset($data['message'])
                ? (is_array($data['message']) ? implode(', ', $data['message']) : (string) $data['message'])
                : '';
            $this->logger->warning(sprintf('TackQuote Connect: %s %s answered HTTP %d', $method, $path, $status));

            return ['ok' => false, 'status' => $status, 'data' => [], 'message' => $message];
        }

        return ['ok' => true, 'status' => $status, 'data' => $data, 'message' => ''];
    }
}
