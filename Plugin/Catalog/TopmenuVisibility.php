<?php
/**
 * Hidden categories leave the storefront's top navigation.
 *
 * HOW THE MENU IS BUILT. Magento_Catalog fills the menu tree in a before-plugin on
 * Magento\Theme\Block\Html\Topmenu::getHtml(), `catalogTopmenu`
 * (Catalog/etc/di.xml:91-93; Catalog/Plugin/Block/Topmenu.php `beforeGetHtml()`), one
 * Magento\Framework\Data\Tree\Node per category with the id `category-node-<id>`, each added
 * under its parent's node. This plugin is declared with a higher sortOrder, so its
 * beforeGetHtml() runs after the tree is complete, and removes each hidden category's node
 * from its parent (Node::removeChild(), Framework/Data/Tree/Node.php:299-303). A removed node
 * takes its children with it, which is also the rule: children of a hidden category are hidden.
 *
 * THE MENU IS BLOCK-CACHED, AND NOT PER CUSTOMER. Topmenu caches its HTML for an hour
 * (Topmenu::getCacheLifetime(), Theme/Block/Html/Topmenu.php:72-75) under the key from
 * Template::getCacheKeyInfo(): store code, template and base URL only
 * (Framework/View/Element/Template.php:327-336), so without a change every visitor would get
 * whichever menu was rendered first. afterGetCacheKeyInfo() adds the visitor's hidden set
 * (CatalogVisibility::varyValue(), '' when nothing is hidden, which leaves the key exactly
 * as it was), so each hidden set has its own cached menu.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Catalog;

use Magento\Framework\Data\Tree\Node;
use Magento\Theme\Block\Html\Topmenu;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class TopmenuVisibility
{
    /** The node id prefix Magento_Catalog's menu plugin gives a category. */
    public const NODE_PREFIX = 'category-node-';

    /** The cache-key entry this plugin adds. */
    public const CACHE_KEY = 'tackquote_hidden_categories';

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
     * Remove the hidden categories' nodes once Magento_Catalog has built the tree.
     *
     * @param Topmenu $subject
     * @param string $outermostClass
     * @param string $childrenWrapClass
     * @param int $limit
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeGetHtml(Topmenu $subject, $outermostClass = '', $childrenWrapClass = '', $limit = 0)
    {
        if ($this->visibility->hiddenCategoryIds() === []) {
            return null;
        }

        foreach ($subject->getMenu()->getAllChildNodes() as $id => $node) {
            $id = (string) $id;
            if (strpos($id, self::NODE_PREFIX) !== 0 || !$node instanceof Node) {
                continue;
            }
            $categoryId = (int) substr($id, strlen(self::NODE_PREFIX));
            $parent = $node->getParent();
            if ($parent instanceof Node && $this->visibility->isCategoryHidden($categoryId)) {
                $parent->removeChild($node);
            }
        }

        return null;
    }

    /**
     * Give each hidden set its own cached menu.
     *
     * @param Topmenu $subject
     * @param array $result
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetCacheKeyInfo(Topmenu $subject, $result)
    {
        $vary = $this->visibility->varyValue();
        if ($vary !== '' && is_array($result)) {
            $result[self::CACHE_KEY] = $vary;
        }

        return $result;
    }
}
