<?php
/**
 * Per-client rate limit for storefront quote submissions.
 *
 * WHY THIS EXISTS — the submit controller is, from the internet's point of view, an
 * unauthenticated public endpoint: the API key lives server-side, so anyone can POST to
 * /tackquote/quote/submit. Every accepted request creates a Buyer and a Quote in the
 * seller's TackQuote tenant. Without a limit, one script floods a seller's pipeline with
 * junk and burns their API plan quota.
 *
 * Tack's own public RFQ route already guards this with `@Throttle({limit: 5, ttl: 60s})`
 * plus a CAPTCHA. The plugin route bypassed both because an API key was assumed to be
 * sufficient authentication — but the key authenticates *the store*, not the visitor
 * driving the browser. This restores an equivalent limit on the visitor.
 *
 * Deliberately cache-backed rather than a DB table: this is throwaway counter state,
 * it must be fast on every submit, and losing it on a cache flush merely resets the
 * window rather than corrupting anything.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

class SubmissionThrottle
{
    /** Matches the limit Tack applies to its own public RFQ endpoint. */
    private const MAX_SUBMISSIONS = 5;

    /** Window in seconds. */
    private const WINDOW = 60;

    private const CACHE_PREFIX = 'tackquote_';
    private const CACHE_TAG = 'TACKQUOTE_THROTTLE';

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(CacheInterface $cache, RemoteAddress $remoteAddress)
    {
        $this->cache = $cache;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * Record an attempt and report whether the caller is over the limit.
     *
     * Counts the attempt before deciding, so a client that keeps hammering stays blocked
     * for the whole window instead of getting one free request each time the count is
     * read.
     *
     * @return bool True when the request should be rejected.
     */
    public function isExceeded(): bool
    {
        return $this->isExceededFor('submit', self::MAX_SUBMISSIONS, self::WINDOW);
    }

    /**
     * The same counter for another public endpoint, in its own bucket.
     *
     * The attachment upload (Controller\Quote\Upload) is limited separately: three files
     * are one quote request, so sharing the submit counter would refuse the request the
     * files belong to. The bucket name is part of the key, so 'submit' keeps the exact key
     * this class has always written.
     *
     * @param string $bucket Lower-case letters only, e.g. 'submit', 'upload'.
     * @param int $max Requests allowed per window.
     * @param int $window Seconds.
     * @return bool True when the request should be rejected.
     */
    public function isExceededFor(string $bucket, int $max, int $window): bool
    {
        $key = $this->cacheKey($bucket);
        $count = (int) $this->cache->load($key);
        $count++;

        // Re-saving on every hit refreshes the TTL, which is intentional: a client that
        // keeps trying stays limited rather than slipping through as the window ages out.
        $this->cache->save((string) $count, $key, [self::CACHE_TAG], $window);

        return $count > $max;
    }

    /**
     * How long a blocked client must wait before retrying.
     *
     * @return int Seconds a blocked client should wait.
     */
    public function getRetryAfter(): int
    {
        return self::WINDOW;
    }

    /**
     * Cache key for this client's submission counter.
     *
     * Keyed on the client IP. Not on session id: a bot simply discards cookies, so a
     * session-keyed counter would reset on every request and enforce nothing.
     *
     * @param string $bucket
     * @return string
     */
    private function cacheKey(string $bucket): string
    {
        $ip = (string) $this->remoteAddress->getRemoteAddress();

        return self::CACHE_PREFIX . $bucket . '_' . hash('sha256', $ip !== '' ? $ip : 'unknown');
    }
}
