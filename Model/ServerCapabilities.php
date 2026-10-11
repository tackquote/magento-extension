<?php
/**
 * What the TackQuote server says it supports for this module, cached per store view. The ONLY
 * reader of `GET /v1/integrations/magento/ping`'s capability list.
 *
 * `capabilities` (tack apps/api/src/modules/integrations/magento/magento-plugin.controller.ts,
 * MAGENTO_PLUGIN_CAPABILITIES): `targetPrice`, `attachments`, `customerIdentity`. A server
 * without the list is an OLDER server, and an older server either refuses a newer field with
 * the whole quote request (`uploadIds`, `uploadToken`) or accepts it and drops it
 * (`targetPrice`). So a field travels only when its flag is listed, the same rule the
 * WooCommerce plugin follows (Tack_Api_Client::server_capabilities()).
 *
 * COLD CACHE: FETCHED ON DEMAND. supports(..., true) and get() ping when nothing is cached
 * (after `cache:flush`, before the first cron tick, after a key change), so the storefront does
 * not lose attachments, the policy-driven identity or net terms for up to five minutes. The
 * read budget is the API client's 3-second GET timeout, paid once: the answer is cached for an
 * hour and Cron\WarmServerCapabilities keeps rewriting it. getCached() never pings (the admin
 * Overview).
 *
 * FAILURE: a failed ping is remembered for FAILURE_TTL (60 s) as "nothing new", so an outage
 * costs at most one timeout per store view per minute; a previously cached list is kept rather
 * than switching features off over one failed call. Every failure is logged at WARNING with the
 * HTTP status and reason, and the latest one is recorded (lastFailure()) for the Overview's
 * cron row; a later success for that store view clears it.
 *
 * NOT FLUSHED BY A SETTINGS SAVE: the entry has its own cache tag (not TACKQUOTE_CONFIG, which
 * Observer\FlushRegistrationConfig cleans on every save of the TackQuote section) and carries a
 * hash of the two settings its answer depends on, `general/api_base_url` and the API key. An
 * unrelated save keeps it; a different URL or key makes it a miss, and the next read re-asks.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;

class ServerCapabilities
{
    /** `lineItems[].targetPrice` reaches the quote line as the buyer's requested price. */
    public const TARGET_PRICE = 'targetPrice';

    /** Quote-request files: `/storefront/v1/quote-upload` + `uploadIds` on the request. */
    public const ATTACHMENTS = 'attachments';

    /** What state() answers: a list is cached, nothing has been asked yet, the last ask failed. */
    public const STATE_OK = 'ok';
    public const STATE_WAITING = 'waiting';
    public const STATE_FAILED = 'failed';

    private const CACHE_KEY_PREFIX = 'tackquote_server_capabilities_';
    private const CACHE_TAG = 'TACKQUOTE_CAPABILITIES';
    private const CACHE_TTL = 3600;
    private const FAILURE_TTL = 60;
    private const LAST_FAILURE_KEY = 'tackquote_server_capabilities_last_failure';
    private const LAST_FAILURE_TTL = 86400;
    private const REASON_MAX = 200;

    /**
     * @var Client
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
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Client $client
     * @param CacheInterface $cache
     * @param Json $json
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $client,
        CacheInterface $cache,
        Json $json,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Whether the server lists a capability.
     *
     * @param string $capability One of the constants above, or AccountIdentity::CAPABILITY.
     * @param int|null $storeId
     * @param bool $mayFetch True: ask the server when nothing is cached. False: cache only.
     * @return bool
     */
    public function supports(string $capability, ?int $storeId = null, bool $mayFetch = false): bool
    {
        $list = $mayFetch ? $this->get($storeId) : $this->getCached($storeId);

        return in_array($capability, $list, true);
    }

    /**
     * The cached list, or []. NEVER calls TackQuote.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getCached(?int $storeId = null): array
    {
        if (!$this->config->isConfigured($storeId)) {
            return [];
        }

        return $this->load($storeId)['caps'];
    }

    /**
     * The list, fetching on a miss (a cold cache, or one written for another URL or key).
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function get(?int $storeId = null): array
    {
        if (!$this->config->isConfigured($storeId)) {
            return [];
        }
        $entry = $this->load($storeId);

        return $entry['hit'] ? $entry['caps'] : $this->refresh($storeId);
    }

    /**
     * Ask the server and store the answer. Blocks on the network.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function refresh(?int $storeId = null): array
    {
        if (!$this->config->isConfigured($storeId)) {
            return [];
        }

        $answer = $this->client->getCapabilities($storeId);
        if (empty($answer['ok'])) {
            $existing = $this->load($storeId);
            $keep = $existing['hit'] && $existing['caps'] !== [];
            $reason = $this->reason($answer);
            $this->logger->warning(sprintf(
                'TackQuote: the server capability check for store %d failed (%s); %s',
                (int) $storeId,
                $reason,
                $keep
                    ? 'keeping the capabilities already known.'
                    : 'treating the server as offering nothing new for 60 seconds.'
            ));
            $this->recordFailure($storeId, $reason);
            if (!$keep) {
                $this->save($storeId, ['failed' => true], self::FAILURE_TTL);
            }

            return $keep ? $existing['caps'] : [];
        }

        $caps = array_values(array_filter((array) ($answer['capabilities'] ?? []), 'is_string'));
        $this->save($storeId, ['caps' => $caps], self::CACHE_TTL);
        $this->clearFailure($storeId);

        return $caps;
    }

    /**
     * Forget the list for a store, so the next check pings again.
     *
     * Called when the server refused a field the cache said it takes: the store now talks
     * to an older server, or one was rolled back.
     *
     * @param int|null $storeId
     * @return void
     */
    public function forget(?int $storeId = null): void
    {
        $this->cache->remove($this->key($storeId));
    }

    /**
     * Where a store view's list stands, for the admin Overview. NEVER calls TackQuote.
     *
     * `ok`: a list is cached (it may be empty: an older server). `failed`: the last check for
     * this store view failed and nothing is cached. `waiting`: nothing has been asked yet.
     *
     * @param int|null $storeId
     * @return string One of the STATE_* constants.
     */
    public function state(?int $storeId = null): string
    {
        $entry = $this->load($storeId);
        if ($entry['hit'] && !$entry['failed']) {
            return self::STATE_OK;
        }
        $failure = $this->lastFailure();
        if ($entry['failed'] || ($failure !== null && $failure['storeId'] === (int) $storeId)) {
            return self::STATE_FAILED;
        }

        return self::STATE_WAITING;
    }

    /**
     * The latest failed capability check of any store view in the last day, or null.
     *
     * @return array{at: string, storeId: int, reason: string}|null `at` is UTC, `Y-m-d H:i:s`.
     */
    public function lastFailure(): ?array
    {
        $raw = $this->cache->load(self::LAST_FAILURE_KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($decoded) || !is_string($decoded['at'] ?? null) || !is_string($decoded['reason'] ?? null)) {
            return null;
        }

        return [
            'at' => $decoded['at'],
            'storeId' => (int) ($decoded['storeId'] ?? 0),
            'reason' => $decoded['reason'],
        ];
    }

    /**
     * Read one store's entry, as a miss when it was written for another URL or key.
     *
     * @param int|null $storeId
     * @return array{hit: bool, failed: bool, caps: string[]}
     */
    private function load(?int $storeId): array
    {
        $miss = ['hit' => false, 'failed' => false, 'caps' => []];
        $raw = $this->cache->load($this->key($storeId));
        if (!is_string($raw) || $raw === '') {
            return $miss;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Exception $e) {
            return $miss;
        }
        // An entry without the credential hash (written by an older module version) is a miss.
        if (!is_array($decoded) || ($decoded['cred'] ?? null) !== $this->credentials($storeId)) {
            return $miss;
        }
        if (!empty($decoded['failed'])) {
            return ['hit' => true, 'failed' => true, 'caps' => []];
        }
        if (!is_array($decoded['caps'] ?? null)) {
            return $miss;
        }

        return [
            'hit' => true,
            'failed' => false,
            'caps' => array_values(array_filter($decoded['caps'], 'is_string')),
        ];
    }

    /**
     * Store one store's entry, stamped with the credentials it was read with.
     *
     * @param int|null $storeId
     * @param array $entry
     * @param int $ttl
     * @return void
     */
    private function save(?int $storeId, array $entry, int $ttl): void
    {
        $entry['cred'] = $this->credentials($storeId);
        $this->cache->save($this->json->serialize($entry), $this->key($storeId), [self::CACHE_TAG], $ttl);
    }

    /**
     * A hash of the two settings the answer depends on: the API base URL and the API key.
     *
     * @param int|null $storeId
     * @return string
     */
    private function credentials(?int $storeId): string
    {
        return hash('sha256', $this->config->getApiBaseUrl($storeId) . "\n" . $this->config->getApiKey($storeId));
    }

    /**
     * Remember the latest failure for the Overview.
     *
     * @param int|null $storeId
     * @param string $reason
     * @return void
     */
    private function recordFailure(?int $storeId, string $reason): void
    {
        $this->cache->save(
            $this->json->serialize([
                'at' => gmdate('Y-m-d H:i:s'),
                'storeId' => (int) $storeId,
                'reason' => $reason,
            ]),
            self::LAST_FAILURE_KEY,
            [self::CACHE_TAG],
            self::LAST_FAILURE_TTL
        );
    }

    /**
     * Forget the recorded failure when it was this store view's.
     *
     * @param int|null $storeId
     * @return void
     */
    private function clearFailure(?int $storeId): void
    {
        $failure = $this->lastFailure();
        if ($failure !== null && $failure['storeId'] === (int) $storeId) {
            $this->cache->remove(self::LAST_FAILURE_KEY);
        }
    }

    /**
     * "HTTP 503: <the API's message>", or the transport message. Never a response body.
     *
     * @param array $answer
     * @return string
     */
    private function reason(array $answer): string
    {
        $status = (int) ($answer['status'] ?? 0);
        $message = trim((string) ($answer['message'] ?? ''));
        if ($message === '') {
            $message = 'no answer';
        }
        $reason = $status > 0 ? sprintf('HTTP %d: %s', $status, $message) : $message;

        return mb_substr($reason, 0, self::REASON_MAX);
    }

    /**
     * Cache key for one store view.
     *
     * @param int|null $storeId
     * @return string
     */
    private function key(?int $storeId): string
    {
        return self::CACHE_KEY_PREFIX . ($storeId ?? 0);
    }
}
