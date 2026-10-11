<?php
/**
 * Categories hidden from particular TackQuote buyer groups, for the visitor of this request.
 *
 * The rule is WooCommerce 1.10.0's (class-tack-catalog-visibility.php), decided by
 * GroupRules::hiddenCategoryRoots():
 *   - a line `<category id>: CODE, @GUESTS` HIDES that category from those groups;
 *   - `@GUESTS` is everybody outside a group: guests, customers TackQuote places in no group,
 *     customers outside "Only for these customer groups";
 *   - a hidden category's children are hidden too;
 *   - TackQuote unreachable: everything is shown, unless "Hide when the buyer group is
 *     unknown" is on, which hides every category hidden from anyone;
 *   - the admin, cron, CLI and integration callers are never filtered (Audience).
 *
 * A product is hidden when it is assigned to any hidden category (WooCommerce
 * `is_hidden_product()`: `array_intersect( terms, hidden )`). The plugins in Plugin/Catalog
 * apply it to the menu, category pages, product pages, layered/search listings and
 * Add to Cart; the page-cache vary value (Plugin\PageCache\BuyerGroupContext) keeps each
 * hidden set's pages apart.
 *
 * Everything is memoised for the request; nothing is persisted.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Store\Model\StoreManagerInterface;

class CatalogVisibility
{
    /**
     * @var GroupRulesConfig
     */
    private $config;

    /**
     * @var Audience
     */
    private $audience;

    /**
     * @var CategoryIndex
     */
    private $index;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var int[]|null
     */
    private $hidden;

    /**
     * @var array<int, true>|null
     */
    private $hiddenProducts;

    /**
     * @param GroupRulesConfig $config
     * @param Audience $audience
     * @param CategoryIndex $index
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        GroupRulesConfig $config,
        Audience $audience,
        CategoryIndex $index,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->audience = $audience;
        $this->index = $index;
        $this->storeManager = $storeManager;
    }

    /**
     * The category ids hidden from this visitor, children included. Sorted.
     *
     * Cheap when the feature is off: the switch and the map are read before any visitor or
     * TackQuote lookup.
     *
     * @return int[]
     */
    public function hiddenCategoryIds(): array
    {
        if ($this->hidden !== null) {
            return $this->hidden;
        }
        $this->hidden = [];

        $storeId = $this->storeId();
        if (!$this->config->isCatalogVisibilityActive($storeId)) {
            return $this->hidden;
        }

        $roots = GroupRules::hiddenCategoryRoots(
            $this->config->getCategoryMap($storeId),
            $this->audience->current(),
            $this->config->hideCatalogWhenUnknown($storeId)
        );

        return $this->hidden = $roots === [] ? [] : $this->index->withDescendants($roots);
    }

    /**
     * Whether the category is hidden from the current visitor.
     *
     * @param int $categoryId
     * @return bool
     */
    public function isCategoryHidden(int $categoryId): bool
    {
        return $categoryId > 0 && in_array($categoryId, $this->hiddenCategoryIds(), true);
    }

    /**
     * Is this product assigned to a category hidden from this visitor?
     *
     * Pass the PARENT for a configurable, bundle or grouped product: that is the product the
     * shopper sees and adds.
     *
     * @param int $productId
     * @return bool
     */
    public function isProductHidden(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }
        if ($this->hiddenProducts === null) {
            $hidden = $this->hiddenCategoryIds();
            $this->hiddenProducts = $hidden === []
                ? []
                : array_fill_keys($this->index->productIdsIn($hidden), true);
        }

        return isset($this->hiddenProducts[$productId]);
    }

    /**
     * Page-cache vary value for the visitor's hidden categories.
     *
     * The page-cache vary value: '' when nothing is hidden from this visitor, otherwise a
     * short hash of the hidden set.
     *
     * Keyed on WHAT is hidden rather than on the group, so two groups that see the same
     * catalogue share one cached copy of each page, and no group code reaches a cookie.
     *
     * @return string
     */
    public function varyValue(): string
    {
        $hidden = $this->hiddenCategoryIds();

        return $hidden === [] ? '' : substr(hash('sha256', implode(',', $hidden)), 0, 16);
    }

    /**
     * Id of the current store.
     *
     * @return int
     */
    private function storeId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
