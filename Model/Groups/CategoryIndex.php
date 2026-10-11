<?php
/**
 * Two reads of Magento's category tables for catalog visibility.
 *
 *   withDescendants()  a hidden category's children are hidden too (WooCommerce:
 *                      "Child categories inherit the rule of their parent",
 *                      class-tack-catalog-visibility.php `hidden_term_ids()`). A category's
 *                      `path` is its ancestors' ids joined by `/` ("1/2/12/40"), so every
 *                      descendant of 12 has "/12/" in its path. Unknown ids drop out here.
 *   productIdsIn()     the products assigned to those categories, from
 *                      `catalog_category_product` (one indexed read per request).
 *
 * Both are per-request reads on indexed columns, called only when the visitor actually has a
 * hidden category; the caller memoises them.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Framework\App\ResourceConnection;

class CategoryIndex
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * The given categories that exist, plus every descendant of them.
     *
     * @param int[] $categoryIds
     * @return int[] Sorted, unique.
     */
    public function withDescendants(array $categoryIds): array
    {
        $categoryIds = $this->ids($categoryIds);
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()->from(
            $this->resource->getTableName('catalog_category_entity'),
            ['entity_id']
        );
        $conditions = [$connection->quoteInto('entity_id IN (?)', $categoryIds)];
        foreach ($categoryIds as $id) {
            $conditions[] = $connection->quoteInto('path LIKE ?', '%/' . $id . '/%');
        }
        $select->where(implode(' OR ', $conditions));

        return $this->ids($connection->fetchCol($select));
    }

    /**
     * Products assigned to any of these categories.
     *
     * @param int[] $categoryIds
     * @return int[] Sorted, unique.
     */
    public function productIdsIn(array $categoryIds): array
    {
        $categoryIds = $this->ids($categoryIds);
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct(true)
            ->from($this->resource->getTableName('catalog_category_product'), ['product_id'])
            ->where('category_id IN (?)', $categoryIds);

        return $this->ids($connection->fetchCol($select));
    }

    /**
     * Positive integers only, sorted and unique.
     *
     * @param array $values
     * @return int[]
     */
    private function ids(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $out[(int) $value] = (int) $value;
            }
        }
        ksort($out);

        return array_values($out);
    }
}
