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

        /*
         * ORDER MATTERS, and the secret-key switch is reset on both sides. The backend URL model
         * is shared, and Magento\Backend\Model\Url::_setRouteParams() updates its `noSecret`
         * flag ONLY when a params array is passed (isset($data['_nosecret']) turns it on, any
         * other array turns it off; read in Magento 2.4.8-p5). A `_nosecret` call followed by a
         * params-less getUrl() therefore built the callback WITHOUT `key/<secret>/`, and with
         * "Add Secret Key to URLs" on (Magento's default) AbstractAction::_processUrlKeys()
         * bounced TackQuote's return to the dashboard: the one-time code was lost and Connect
         * could never finish (devstore Q/A finding #25).
         *
         * So: the callback first, with the key switched on explicitly; the key-less dashboard
         * URL second; the key switched back on so nothing later in this request inherits it.
         * NOT ['_nosecret' => false]: the vendor test is isset(), so that would turn the key OFF.
         */
        $this->backendUrl->turnOnSecretKey();
        $redirectUri = $this->backendUrl->getUrl(self::CALLBACK_ROUTE);
        $adminUrl = $this->backendUrl->getUrl('tackquote/dashboard/index', ['_nosecret' => true]);
        $this->backendUrl->turnOnSecretKey();

        return [
            'siteUrl' => $this->siteUrl(),
            'adminUrl' => $adminUrl,
            'redirectUri' => $redirectUri,
            'siteName' => (string) $this->scopeConfig->getValue('general/store_information/name', $default),
            'pluginVersion' => is_array($module) ? (string) ($module['setup_version'] ?? '') : '',
            'platformVersion' => (string) $this->productMetadata->getVersion(),
            'locale' => (string) $this->scopeConfig->getValue('general/locale/code', $default),
        ];
    }
}
