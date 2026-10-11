<?php
/**
 * What the module tells TackQuote about its accepted-quote checkout landing, on every ping.
 *
 * Model\Api\Client sends `X-TackQuote-Quote-Checkout-Url: <value>` with `GET
 * /integrations/magento/ping` (the admin's Test connection and the capability cron, every five
 * minutes). TackQuote records it on the store connection (tack
 * common/connectors/magento-quote-checkout-landing.ts) and offers the buyer "Checkout in the
 * store" only while it holds a usable value:
 *
 *   https://<store base URL>tackquote/checkout/start   the feature is on and the store is https
 *   off                                                anything else (TackQuote then forgets it)
 *
 * The base URL is the store view's own link URL (Store::getBaseUrl(URL_TYPE_LINK, true)), so a
 * store-code or `index.php/` prefix is kept. TackQuote accepts the URL only on the host the
 * seller entered for the store connection, so a mismatch is refused there rather than guessed
 * here. An http store is reported as `off`: the token travels in this URL.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;

class LandingUrl
{
    /** The header the ping carries. */
    public const HEADER = 'X-TackQuote-Quote-Checkout-Url';

    /** The value that says "this store does not take checkout links". */
    public const OFF = 'off';

    /** The landing route (etc/frontend/routes.xml frontName + Controller\Checkout\Start). */
    public const ROUTE = 'tackquote/checkout/start';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Config $config, StoreManagerInterface $storeManager)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * The header value for a store view.
     *
     * @param int|null $storeId
     * @return string
     */
    public function headerValue(?int $storeId = null): string
    {
        if (!$this->config->isQuoteCheckoutActive($storeId)) {
            return self::OFF;
        }
        try {
            $base = (string) $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
        } catch (\Exception $e) {
            return self::OFF;
        }
        if (stripos($base, 'https://') !== 0) {
            return self::OFF;
        }

        return rtrim($base, '/') . '/' . self::ROUTE;
    }
}
