<?php
/**
 * What the TackQuote server says it supports for this module, cached per store view.
 *
 * `GET /v1/integrations/magento/ping` answers `capabilities` (tack
 * apps/api/src/modules/integrations/magento/magento-plugin.controller.ts,
 * MAGENTO_PLUGIN_CAPABILITIES): `targetPrice` and `attachments`. A server without the list is
 * an OLDER server, and an older server either refuses a newer field with the whole quote
 * request (`uploadIds`, `uploadToken`) or accepts it and drops it (`targetPrice`). So a field
 * travels only when its flag is listed — the same rule the WooCommerce plugin follows
 * (Tack_Api_Client::server_capabilities()).
 *
 * NOT IN THE RENDER PATH — Block\QuoteList renders on every storefront page and reads
 * getCached() only; Cron\WarmServerCapabilities fetches. The storefront POST controllers may
 * use get(), which fetches on a miss: the shopper is already waiting on a network call there.
 *
 * FAILURE — a failed ping is remembered for FAILURE_TTL as "nothing new", never as a list, so
 * an outage does not turn every upload into a timeout; a previously cached list is kept, as
 * the registration policy is, rather than switching features off over one failed cron run.
 * Shares the TACKQUOTE_CONFIG cache tag, so saving the settings (a new key, another URL)
 * flushes it with the policy (Observer\FlushRegistrationConfig).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Api\Client;

class ServerCapabilities
{
    /** `lineItems[].targetPrice` reaches the quote line as the buyer's requested price. */
    public const TARGET_PRICE = 'targetPrice';

    /** Quote-request files: `/storefront/v1/quote-upload` + `uploadIds` on the request. */
    public const ATTACHMENTS = 'attachments';

    private const CACHE_KEY_PREFIX = 'tackquote_server_capabilities_';
    private const CACHE_TAG = 'TACKQUOTE_CONFIG';
    private const CACHE_TTL = 3600;
    private const FAILURE_MARKER = '__tackquote_ping_failed__';
    private const FAILURE_TTL = 600;

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
     * @param Client $client
     * @param CacheInterface $cache
     * @param Json $json
     * @param Config $config
     */
    public function __construct(Client $client, CacheInterface $cache, Json $json, Config $config)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
        $this->config = $config;
    }

    /**
     * Whether the server lists a capability.
     *
     * @param string $capability One of the constants above.
     * @param int|null $storeId
     * @param bool $mayFetch False on a page render: answer from cache only.
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
     * The list, fetching on a miss.
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
            if ($existing['hit'] && $existing['caps'] !== []) {
                return $existing['caps'];
            }
            $this->cache->save(self::FAILURE_MARKER, $this->key($storeId), [self::CACHE_TAG], self::FAILURE_TTL);

            return [];
        }

        $caps = array_values(array_filter((array) ($answer['capabilities'] ?? []), 'is_string'));
        $this->cache->save($this->json->serialize($caps), $this->key($storeId), [self::CACHE_TAG], self::CACHE_TTL);

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
     * Read one store's entry.
     *
     * @param int|null $storeId
     * @return array{hit: bool, caps: string[]}
     */
    private function load(?int $storeId): array
    {
        $raw = $this->cache->load($this->key($storeId));
        if ($raw === self::FAILURE_MARKER) {
            return ['hit' => true, 'caps' => []];
        }
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
            } catch (\Exception $e) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                return ['hit' => true, 'caps' => array_values(array_filter($decoded, 'is_string'))];
            }
        }

        return ['hit' => false, 'caps' => []];
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
