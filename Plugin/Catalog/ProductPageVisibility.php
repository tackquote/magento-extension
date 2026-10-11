<?php
/**
 * A product in a hidden category answers Magento's 404 on its own page.
 *
 * Magento\Catalog\Helper\Product::initProduct() loads the product a product page (and the
 * other product routes: reviews, compare, gallery) is about, and answers `false` for one the
 * visitor may not see (disabled, not visible, not in this website) (Catalog/Helper/
 * Product.php:410-478). Magento\Catalog\Helper\Product\View::prepareAndRender() turns `false`
 * into "Product is not loaded" (Catalog/Helper/Product/View.php:268-270), which the product
 * controller answers with `noroute`. This after-plugin answers `false` for a product assigned
 * to a category hidden from the visitor, so it joins that path: the same 404 as a product that
 * does not exist. WooCommerce `block_hidden_product()` does the same.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Catalog;

use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\Product;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class ProductPageVisibility
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
     * Answer "not loaded" for a product in a category hidden from the visitor.
     *
     * @param ProductHelper $subject
     * @param Product|false $result
     * @return Product|false
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterInitProduct(ProductHelper $subject, $result)
    {
        if ($result instanceof Product && $this->visibility->isProductHidden((int) $result->getId())) {
            return false;
        }

        return $result;
    }
}
