<?php
/**
 * Decide the visitor's hidden categories BEFORE the page is built.
 *
 * WHY. On a page the full-page cache may store, Magento DEPERSONALISES the session once the
 * layout's elements are generated: Magento\Customer\Model\Layout\DepersonalizePlugin::
 * afterGenerateElements() clears the customer session and leaves only the customer GROUP
 * (Customer/Model/Layout/DepersonalizePlugin.php:101-110), whenever Magento_PageCache is on,
 * the request is a non-AJAX GET or HEAD and the layout is cacheable
 * (PageCache/Model/DepersonalizeChecker.php:53-57). Everything rendered after that point (the
 * top menu, the product list) and the vary string computed when the response is sent would
 * see a signed-in customer as a guest, and hand a trade customer the guest catalogue.
 *
 * Magento core meets the same constraint by setting its own page variation from a
 * `beforeExecute` plugin on Magento\Framework\App\ActionInterface, before any layout exists
 * (Customer/Model/App/Action/ContextPlugin.php:47-58, declared in Customer/etc/frontend/
 * di.xml). This plugin runs at that same point and resolves CatalogVisibility, which memoises
 * the visitor's hidden set for the rest of the request; the menu, the listings, the product
 * page and Plugin\PageCache\BuyerGroupContext all read that memo.
 *
 * Costs nothing when catalog visibility is off: hiddenCategoryIds() reads the switch first.
 *
 * Declared in etc/frontend/di.xml only.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\PageCache;

use Magento\Framework\App\ActionInterface;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class BuyerGroupWarmup
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
     * @param ActionInterface $subject
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeExecute(ActionInterface $subject): void
    {
        $this->visibility->hiddenCategoryIds();
    }
}
