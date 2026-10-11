<?php
/**
 * Product-page placeholder for the signed-in customer's B2B data.
 *
 * Renders NOTHING about the customer. Product pages are full-page cached, so this block
 * only says where the data goes and which product the page is about; the data itself is
 * fetched after the page loads by view/frontend/web/js/b2b-display.js from
 * Controller/B2b/Display.php (see that controller for the full-page-cache reasoning).
 * Everything this block reads is store configuration and the product id, which are the
 * same for every visitor of the same URL, so the block is safe in a cached page.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;

class B2bDisplay extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * Reads the product the page is about (Magento's `current_product`).
     *
     * @var CatalogHelper
     */
    private $catalogHelper;

    /**
     * @param Context $context
     * @param Config $config
     * @param CatalogHelper $catalogHelper
     * @param array $data
     */
    public function __construct(Context $context, Config $config, CatalogHelper $catalogHelper, array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->catalogHelper = $catalogHelper;
    }

    /**
     * Whether any B2B display feature is switched on (and the module can call TackQuote).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->getEnabledB2bFeatures() !== [];
    }

    /**
     * The id of the product this page is about, or 0.
     *
     * @return int
     */
    public function getProductId(): int
    {
        $product = $this->catalogHelper->getProduct();

        return $product instanceof Product ? (int) $product->getId() : 0;
    }

    /**
     * The endpoint the script posts to.
     *
     * @return string
     */
    public function getEndpointUrl(): string
    {
        return $this->getUrl('tackquote/b2b/display');
    }
}
