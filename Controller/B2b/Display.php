<?php
/**
 * `POST /tackquote/b2b/display` — the signed-in customer's B2B data for one product.
 *
 * Called by view/frontend/web/js/b2b-display.js from the product page. Server-side so the
 * TackQuote API key never reaches the browser, exactly like Controller/Quote/Submit.php.
 *
 * WHY A POST, AND WHY THE PAGE ITSELF CARRIES NO B2B DATA — product pages are full-page
 * cached (built-in cache or Varnish), so anything rendered into their HTML is served to
 * the next visitor too. Adobe's private-content guidance is to keep per-customer data out
 * of cacheable pages and load it on the client, and states that only GET and HEAD are
 * cacheable (developer.adobe.com/commerce/php/development/cache/page/private-content and
 * .../cache/page/public-content). So the page carries an empty placeholder and this POST,
 * which neither cache will store, fills it. The response is also marked
 * `Cache-Control: no-store, private` for any proxy in between.
 *
 * CSRF — a non-GET controller marked HttpPostActionInterface gets Magento's built-in CSRF
 * protection: the request must carry the session's `form_key`
 * (developer.adobe.com/commerce/php/development/security/cross-site-request-forgery).
 * The JS sends it from the `form_key` cookie. Nothing here changes state, but the answer
 * is the customer's private pricing, so a cross-site page must not be able to read it
 * through the customer's cookies either.
 *
 * WHO IS ASKED ABOUT — only the signed-in customer in this session, by THEIR OWN email
 * address. Nothing the browser sends selects a customer: the browser sends a product id
 * and nothing else. A guest, a customer outside the merchant's B2B customer groups, an
 * unknown or disabled product, or any failure all get the same empty answer, so the page
 * shows Magento's own price and nothing else.
 *
 * DISPLAY ONLY — nothing here touches the cart or checkout.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\B2b;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\B2bDisplay\DataProvider;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class Display implements HttpPostActionInterface
{
    /**
     * Product types whose page price changes with the shopper's selection (a configurable's
     * swatch, a bundle's options, a grouped product's children). The SKU this endpoint
     * knows is the PARENT's, and a parent-level account price beside a price box that has
     * just switched to a variant would describe a different product. Their account price
     * and volume table are therefore not shown; the badge and order-limit notice, which do
     * not describe a price, still are.
     */
    private const SELECTION_PRICED_TYPES = ['configurable', 'bundle', 'grouped'];

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var DataProvider
     */
    private $dataProvider;

    /**
     * @var Presenter
     */
    private $presenter;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     * @param Config $config
     * @param DataProvider $dataProvider
     * @param Presenter $presenter
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        Config $config,
        DataProvider $dataProvider,
        Presenter $presenter
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->config = $config;
        $this->dataProvider = $dataProvider;
        $this->presenter = $presenter;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        /** @var JsonResult $result */
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, private', true);

        return $result->setData($this->payload());
    }

    /**
     * The JSON body: the presented B2B data, or the empty payload.
     *
     * @return array
     */
    private function payload(): array
    {
        $empty = Presenter::EMPTY_PAYLOAD;

        if (!$this->customerSession->isLoggedIn()) {
            return $empty;
        }

        $store = $this->storeManager->getStore();
        if (!$store instanceof Store) {
            return $empty;
        }
        $storeId = (int) $store->getId();
        if ($this->config->getEnabledB2bFeatures($storeId) === []) {
            return $empty;
        }
        if (!$this->customerGroupAllowed($storeId)) {
            return $empty;
        }

        $product = $this->product((int) $this->request->getParam('product_id', 0), $store);
        if ($product === null) {
            return $empty;
        }

        // Not even asked for a selection-priced product: nothing it answered could be shown.
        $only = in_array((string) $product->getTypeId(), self::SELECTION_PRICED_TYPES, true)
            ? [Config::B2B_ORDER_LIMITS, Config::B2B_BUYER_GROUP]
            : null;

        // The service-contract DTO (CustomerInterface::getEmail is declared), not the model.
        $customer = $this->customerSession->getCustomerData();
        $currency = (string) $store->getCurrentCurrencyCode();
        $facts = $this->dataProvider->get(
            $storeId,
            (int) $this->customerSession->getCustomerId(),
            (string) $customer->getEmail(),
            (string) $product->getSku(),
            $currency,
            $only
        );

        return $this->presenter->present($facts, $product, $store, $currency);
    }

    /**
     * Whether the signed-in customer's group may see B2B data.
     *
     * An empty selection means every signed-in customer. See the `customer_groups` field
     * comment in etc/adminhtml/system.xml for why a merchant would narrow it.
     *
     * @param int $storeId
     * @return bool
     */
    private function customerGroupAllowed(int $storeId): bool
    {
        $allowed = QuoteOnlyRules::normaliseGroups($this->config->getB2bCustomerGroups($storeId));
        if ($allowed === []) {
            return true;
        }

        return in_array((int) $this->customerSession->getCustomerGroupId(), $allowed, true);
    }

    /**
     * The requested product, only if this store would show it to a shopper.
     *
     * @param int $productId
     * @param Store $store
     * @return Product|null
     */
    private function product(int $productId, Store $store): ?Product
    {
        if ($productId < 1) {
            return null;
        }
        try {
            $product = $this->productRepository->getById($productId, false, (int) $store->getId());
        } catch (\Exception $e) {
            return null;
        }
        if (!$product instanceof Product
            || (int) $product->getStatus() !== Status::STATUS_ENABLED
            || !$product->isVisibleInSiteVisibility()
            || !in_array((int) $store->getWebsiteId(), array_map('intval', (array) $product->getWebsiteIds()), true)
            || (string) $product->getSku() === ''
        ) {
            return null;
        }

        return $product;
    }
}
