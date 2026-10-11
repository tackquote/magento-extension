<?php
/**
 * The two informational headers every request to TackQuote carries, like the WooCommerce
 * plugin's (class-tack-api-client.php, request()):
 *
 *   X-TackQuote-Plugin-Version  this module's version (etc/module.xml setup_version, which
 *                               the release checks keep equal to composer.json "version")
 *   X-TackQuote-Site-Url        the store's base URL, without a trailing slash
 *
 * ADDITIVE ONLY. Neither header is a credential: X-Api-Key stays the only one, and both
 * values are public facts about the store. A header whose value cannot be worked out is left
 * out rather than sent empty or guessed.
 *
 * What TackQuote does with them today (tack apps/api/src/common/connectors/
 * woocommerce-plugin-version.ts and woocommerce-store-url.ts): it records them for
 * WooCommerce routes only. On /storefront/v1 reads that carry X-TackQuote-Platform: magento
 * the site URL is ignored (api-key-storefront-identity.resolver.ts, assertedCustomer()); on
 * a read without that header it is read as a WooCommerce store hint that can only narrow the
 * tenant's own WooCommerce connections, and a URL matching none of them falls back to the
 * previous behaviour (storefront-buyer.ts, resolveWoocommerceIntegrationId()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Api;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class PluginHeaders
{
    public const HEADER_VERSION = 'X-TackQuote-Plugin-Version';
    public const HEADER_SITE_URL = 'X-TackQuote-Site-Url';

    /** This module's name in etc/module.xml. */
    private const MODULE_NAME = 'TackQuote_Quotes';

    /**
     * The version shape TackQuote stores (MAJOR.MINOR.PATCH, optional -pre.release, at most 32
     * characters; tack woocommerce-plugin-version.ts). Anything else is not sent.
     */
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    /** Longest site URL sent. */
    private const SITE_URL_MAX = 2048;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param ModuleListInterface $moduleList
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(ModuleListInterface $moduleList, StoreManagerInterface $storeManager)
    {
        $this->moduleList = $moduleList;
        $this->storeManager = $storeManager;
    }

    /**
     * The headers for a request made for this store (null: the current store).
     *
     * @param int|null $storeId
     * @return array<string, string>
     */
    public function get(?int $storeId = null): array
    {
        $headers = [];

        $version = $this->version();
        if ($version !== '') {
            $headers[self::HEADER_VERSION] = $version;
        }

        $siteUrl = $this->siteUrl($storeId);
        if ($siteUrl !== '') {
            $headers[self::HEADER_SITE_URL] = $siteUrl;
        }

        return $headers;
    }

    /**
     * This module's version from etc/module.xml, or '' when it is not a version.
     *
     * @return string
     */
    private function version(): string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);
        $version = is_array($module) && isset($module['setup_version']) && is_string($module['setup_version'])
            ? trim($module['setup_version'])
            : '';

        return strlen($version) <= 32 && preg_match(self::VERSION_PATTERN, $version) === 1 ? $version : '';
    }

    /**
     * The store's web base URL without a trailing slash, or '' when it cannot be read or is
     * not a plain http(s) URL.
     *
     * @param int|null $storeId
     * @return string
     */
    private function siteUrl(?int $storeId): string
    {
        try {
            $url = (string) $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Exception $e) {
            return '';
        }

        $url = rtrim(trim($url), '/');
        if ($url === ''
            || strlen($url) > self::SITE_URL_MAX
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || preg_match('#^https?://[^/?\#]+#i', $url) !== 1
        ) {
            return '';
        }

        return $url;
    }
}
