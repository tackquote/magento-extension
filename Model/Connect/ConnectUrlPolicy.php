<?php
/**
 * URL rules of "Connect with TackQuote", on this side of the wire.
 *
 * Two decisions, both pure:
 *
 *  1. May this store use Connect at all? Its base URL must be https, or plain
 *     http only on a NON-PUBLIC host (a local development store). The rule is
 *     the WooCommerce plugin's `Tack_Settings::is_non_public_host()` and the
 *     TackQuote server's `isNonPublicStoreHost()` (tack
 *     `storefront-connect-site.ts`), which applies the same rule again, so a
 *     store this side lets through cannot pass there unless both agree.
 *
 *  2. May the admin's browser be sent to the consent URL TackQuote answered
 *     with? Only to https (http only on a development host), only to
 *     app.tackquote.com when the API base URL is the default one, only to the
 *     Magento consent page, and only for the request id TackQuote just issued.
 *     A tampered or misconfigured answer cannot turn this button into an open
 *     redirect. Port of `Tack_Connect::connect_url_host()`.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

class ConnectUrlPolicy
{
    /** The default API base URL (Model\Config::DEFAULT_API_BASE_URL). */
    public const DEFAULT_API_BASE_URL = 'https://api.tackquote.com/v1';

    /** Consent-page host for the default API base URL. */
    public const DEFAULT_CONNECT_HOST = 'app.tackquote.com';

    /** The consent page path suffix TackQuote answers with (tack `MAGENTO_CONNECT_PLATFORM.consentPath`). */
    public const CONSENT_PATH_SUFFIX = '/connect/magento';

    /** A Magento connect request id (tack `MAGENTO_CONNECT_STORE`, prefix `mgr_`). */
    public const REQUEST_ID = '/^mgr_[A-Za-z0-9_-]{22}$/';

    private const NON_PUBLIC_SUFFIXES = ['.local', '.localhost', '.test', '.internal'];

    /**
     * Localhost, a single-label name, a .local/.localhost/.test/.internal name,
     * or an IP literal in private or reserved space.
     *
     * @param string $host
     * @return bool
     */
    public static function isNonPublicHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host, " \t\n\r\0\x0B[]")), '.');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Public routable space passes the flagged check, so false means private/reserved.
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }
        if ($host === 'localhost' || strpos($host, '.') === false) {
            return true;
        }
        foreach (self::NON_PUBLIC_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a URL's scheme and host allow Connect: https, or http on a non-public host.
     *
     * @param string $url
     * @return bool
     */
    public static function isAllowedUrl(string $url): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- pure parse, no I/O.
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && self::isNonPublicHost((string) $parts['host']);
    }

    /**
     * The host of the consent page TackQuote answered with, or '' when the
     * browser must not be sent there.
     *
     * @param string $connectUrl
     * @param string $requestId
     * @param string $apiBaseUrl The configured API base URL.
     * @return string
     */
    public static function consentHost(string $connectUrl, string $requestId, string $apiBaseUrl): string
    {
        if (!preg_match(self::REQUEST_ID, $requestId)) {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- pure parse, no I/O.
        $parts = parse_url($connectUrl);
        if (!is_array($parts) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            return '';
        }
        if (!self::isAllowedUrl($connectUrl)) {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        $api = rtrim(trim($apiBaseUrl), '/');
        if (($api === '' || $api === self::DEFAULT_API_BASE_URL) && $host !== self::DEFAULT_CONNECT_HOST) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        if (substr($path, -strlen(self::CONSENT_PATH_SUFFIX)) !== self::CONSENT_PATH_SUFFIX) {
            return '';
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (($query['request'] ?? '') !== $requestId) {
            return '';
        }

        return $host;
    }
}
