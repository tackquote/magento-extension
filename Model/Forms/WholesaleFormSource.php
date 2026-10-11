<?php
/**
 * The seller's wholesale form definition, normalised and cached.
 *
 * Read on every render of the Wholesale account tab or a CMS page holding the form, and the
 * answer changes only when the seller edits the form, so it is cached per store and slug:
 * 5 minutes on success, 60 seconds on failure (a short negative cache, so an outage does
 * not turn every page view into a timeout). The WooCommerce plugin uses the same two TTLs.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

class WholesaleFormSource
{
    private const CACHE_PREFIX = 'tackquote_wsform_';
    private const CACHE_TAG = 'TACKQUOTE_CONFIG';
    private const SUCCESS_TTL = 300;
    private const FAILURE_TTL = 60;
    private const FAILURE_MARKER = '__tackquote_form_unavailable__';

    /**
     * @var AccountApiClient
     */
    private $client;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param AccountApiClient $client
     * @param FormDefinition $definition
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        AccountApiClient $client,
        FormDefinition $definition,
        CacheInterface $cache,
        Json $json
    ) {
        $this->client = $client;
        $this->definition = $definition;
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * The wholesale form with this slug, normalised.
     *
     * The normalised form, or null when TackQuote has none to show (no form with this slug,
     * no fields, or TackQuote unreachable).
     *
     * @param string $slug
     * @param int|null $storeId
     * @param bool $force Bypass the cache (a submission re-reads the live form).
     * @return array|null
     */
    public function get(string $slug, ?int $storeId = null, bool $force = false): ?array
    {
        $key = self::CACHE_PREFIX . (int) $storeId . '_' . ($slug !== '' ? $slug : '_default');
        if (!$force) {
            $raw = $this->cache->load($key);
            if ($raw === self::FAILURE_MARKER) {
                return null;
            }
            if (is_string($raw) && $raw !== '') {
                try {
                    $cached = $this->json->unserialize($raw);
                } catch (\Exception $e) {
                    $cached = null;
                }
                if (is_array($cached) && !empty($cached['fields'])) {
                    return $cached;
                }
            }
        }

        $answer = $this->client->getWholesaleForm($slug, $storeId);
        $form = !empty($answer['ok']) && is_array($answer['data'] ?? null)
            ? $this->definition->normalise($answer['data'])
            : null;
        if ($form === null || $form['fields'] === []) {
            $this->cache->save(self::FAILURE_MARKER, $key, [self::CACHE_TAG], self::FAILURE_TTL);

            return null;
        }
        $this->cache->save($this->json->serialize($form), $key, [self::CACHE_TAG], self::SUCCESS_TTL);

        return $form;
    }
}
