<?php
/**
 * What "Connect with TackQuote" tells TackQuote about this store at start.
 *
 *   siteUrl          the DEFAULT-scope base URL the Admin's own URLs are built
 *                    on: `web/secure/base_url` when `web/secure/use_in_adminhtml`
 *                    is on (the usual production setting), else
 *                    `web/unsecure/base_url`. TackQuote requires the return
 *                    route to sit under it, on the same host.
 *   adminUrl         this module's Admin dashboard URL, without a secret key.
 *   redirectUri      `tackquote/connect/callback` as the backend URL model
 *                    builds it (with the session-bound secret key when "Add
 *                    Secret Key to URLs" is on).
 *   siteName         Stores > Configuration > General > Store Information > Name.
 *   pluginVersion    this module's setup_version.
 *   platformVersion  the Magento version.
 *   locale           the default-scope locale.
 *
 * No customer, order or product data.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;

class StoreInfo
{
    /** The Admin route TackQuote returns the browser to (tack MAGENTO_CONNECT_CALLBACK_ROUTE). */
    public const CALLBACK_ROUTE = 'tackquote/connect/callback';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var BackendUrl
     */
    private $backendUrl;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param BackendUrl $backendUrl
     * @param ProductMetadataInterface $productMetadata
     * @param ModuleListInterface $moduleList
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        BackendUrl $backendUrl,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->backendUrl = $backendUrl;
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
    }

    /**
     * This store's base URL, as Connect declares and compares it.
     *
     * @return string
     */
    public function siteUrl(): string
    {
        $default = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $secure = $this->scopeConfig->isSetFlag('web/secure/use_in_adminhtml', $default);

        return (string) $this->scopeConfig->getValue(
            $secure ? 'web/secure/base_url' : 'web/unsecure/base_url',
            $default
        );
    }

    /**
     * The start request's store description.
     *
     * @return array
     */
    public function describe(): array
    {
        $default = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $module = $this->moduleList->getOne('TackQuote_Quotes');

        return [
            'siteUrl' => $this->siteUrl(),
            'adminUrl' => $this->backendUrl->getUrl('tackquote/dashboard/index', ['_nosecret' => true]),
            'redirectUri' => $this->backendUrl->getUrl(self::CALLBACK_ROUTE),
            'siteName' => (string) $this->scopeConfig->getValue('general/store_information/name', $default),
            'pluginVersion' => is_array($module) ? (string) ($module['setup_version'] ?? '') : '',
            'platformVersion' => (string) $this->productMetadata->getVersion(),
            'locale' => (string) $this->scopeConfig->getValue('general/locale/code', $default),
        ];
    }
}
