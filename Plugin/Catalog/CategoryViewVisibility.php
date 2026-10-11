<?php
/**
 * A hidden category's page answers Magento's 404, as a category that does not exist does.
 *
 * Magento\Catalog\Controller\Category\View::execute() reads the category from the `id` request
 * parameter (Category/View.php:175, 261) and, when it cannot show it, forwards to `noroute`
 * (Category/View.php:266-268). This around-plugin answers the same forward before the category
 * is loaded, so nothing about the hidden category (name, description, products) is rendered.
 * WooCommerce answers a hidden product's URL with a 404 for the same reason
 * (class-tack-catalog-visibility.php `block_hidden_product()`).
 *
 * The 404 is cache-safe: the page-cache vary value (Plugin\PageCache\BuyerGroupContext) keeps a
 * visitor who may see the category from being served another visitor's 404, and the other way
 * round.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Catalog;

use Magento\Catalog\Controller\Category\View;
use Magento\Framework\Controller\Result\ForwardFactory;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class CategoryViewVisibility
{
    /**
     * @var CatalogVisibility
     */
    private $visibility;

    /**
     * @var ForwardFactory
     */
    private $forwardFactory;

    /**
     * @param CatalogVisibility $visibility
     * @param ForwardFactory $forwardFactory
     */
    public function __construct(CatalogVisibility $visibility, ForwardFactory $forwardFactory)
    {
        $this->visibility = $visibility;
        $this->forwardFactory = $forwardFactory;
    }

    /**
     * @param View $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExecute(View $subject, callable $proceed)
    {
        $categoryId = (int) $subject->getRequest()->getParam('id', 0);
        if ($categoryId > 0 && $this->visibility->isCategoryHidden($categoryId)) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        return $proceed();
    }
}
