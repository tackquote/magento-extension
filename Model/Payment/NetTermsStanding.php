<?php
/**
 * The signed-in customer's TackQuote net-terms standing, read once and shared.
 *
 * Two places ask: the "Pay on net terms" method (is it offered?) and the Net terms tab (does
 * the customer already have terms?). Both read through here, so a page that shows both makes
 * one call and the two never disagree within a minute. Port of the WooCommerce plugin's
 * Tack_Net_Terms_Standing.
 *
 * Cached per customer for 60 seconds in Magento's cache, keyed by customer id AND a hash of
 * the email and install id it was read for, so a changed account email is never answered
 * from the old one. Only a successful answer is cached. `$fresh` (an order being placed)
 * bypasses both caches. Never in the full-page cache: the method list and the account tab
 * are not cacheable pages.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use TackQuote\Quotes\Model\Forms\AccountApiClient;
use TackQuote\Quotes\Model\Forms\AccountIdentity;

class NetTermsStanding
{
    private const CACHE_PREFIX = 'tackquote_nt_';
    private const CACHE_TAG = 'TACKQUOTE_NET_TERMS';
    private const CACHE_TTL = 60;

    /**
     * @var AccountApiClient
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
     * Answers already read in THIS request: customer id => [hash, answer].
     *
     * @var array<int, array{0: string, 1: array}>
     */
    private $memo = [];

    /**
     * @param AccountApiClient $client
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(AccountApiClient $client, CacheInterface $cache, Json $json)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * The decoded `NetTermsResult`, or null when it could not be read (callers fail closed).
     *
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @param bool $fresh Bypass both caches.
     * @return array|null
     */
    public function read(AccountIdentity $who, ?int $storeId = null, bool $fresh = false): ?array
    {
        if (!$who->isSignedIn()) {
            return null;
        }
        $id = $who->getCustomerId();
        $hash = hash('sha256', $who->getEmail() . '|' . implode('|', $who->headers()) . '|' . (int) $storeId);
        $key = self::CACHE_PREFIX . $id;

        if (!$fresh) {
            if (isset($this->memo[$id]) && $this->memo[$id][0] === $hash) {
                return $this->memo[$id][1];
            }
            $raw = $this->cache->load($key);
            if (is_string($raw) && $raw !== '') {
                try {
                    $entry = $this->json->unserialize($raw);
                } catch (\Exception $e) {
                    $entry = null;
                }
                if (is_array($entry) && ($entry['hash'] ?? null) === $hash && is_array($entry['answer'] ?? null)) {
                    $this->memo[$id] = [$hash, $entry['answer']];

                    return $entry['answer'];
                }
            }
        }

        $result = $this->client->getNetTerms($who, $storeId);
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
            unset($this->memo[$id]);

            return null;
        }
        $answer = $result['data'];
        $this->memo[$id] = [$hash, $answer];
        $this->cache->save(
            $this->json->serialize(['hash' => $hash, 'answer' => $answer]),
            $key,
            [self::CACHE_TAG],
            self::CACHE_TTL
        );

        return $answer;
    }

    /**
     * Forget a customer's standing (after they file an application).
     *
     * @param int $customerId
     * @return void
     */
    public function forget(int $customerId): void
    {
        unset($this->memo[$customerId]);
        $this->cache->remove(self::CACHE_PREFIX . $customerId);
    }
}
