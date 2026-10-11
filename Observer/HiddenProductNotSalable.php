<?php
/**
 * A product in a category hidden from the visitor cannot be bought.
 *
 * WooCommerce makes such a product not purchasable (`woocommerce_is_purchasable`,
 * class-tack-catalog-visibility.php `filter_purchasable()`). Magento's equivalent is
 * salability: Magento\Catalog\Model\Product::isSalable() dispatches
 * `catalog_product_is_salable_after` with a mutable `salable` object and keeps whatever the
 * observers leave in it (Catalog/Model/Product.php:1718-1728). Magento\Quote\Model\Quote::
 * addProduct() refuses a product that is not salable ("Product that you are trying to add is
 * not available.", Quote/Model/Quote.php:1658-1661), and that is the one model every add path
 * funnels through: the product page, wishlist, reorder, the REST cart-item API and GraphQL.
 *
 * Declared in etc/frontend, etc/webapi_rest and etc/graphql events.xml; Audience leaves the
 * admin and integration callers unfiltered.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class HiddenProductNotSalable implements ObserverInterface
{
    /**
     * @var CatalogVisibility
     */
    private $visibility;

    /**
     * @param CatalogVisibility $visibility
     */
    public function __construct(CatalogVisibility $visibility)
    {
        $this->visibility = $visibility;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $product = $event->getData('product');
        $salable = $event->getData('salable');
        if (!$product instanceof Product || !$salable instanceof DataObject || !$salable->getData('is_salable')) {
            return;
        }

        if ($this->visibility->isProductHidden((int) $product->getId())) {
            $salable->setData('is_salable', false);
        }
    }
}
