<?php
/**
 * Is ONE product quote-only? Reads the `tackquote_quote_only` product attribute
 * (Setup/Patch/Data/AddQuoteOnlyProductAttribute.php).
 *
 * Per-product quote-only applies to every storefront visitor, whatever "Applies to" says and
 * whether or not the store-wide mode is on — the WooCommerce plugin's per-product switch
 * (1.10.0+) behaves the same way. Whether it may be enforced at all (an API key, so a quote
 * button can render) is QuoteOnlyMode's decision, not this class's.
 *
 * WHERE THE VALUE COMES FROM. A product loaded for a page or a listing carries the attribute
 * when it is `used_in_product_listing` (the data patch sets that), so the loaded value is
 * used when present. Otherwise — a product object built by a cart or API path that did not
 * load it — the raw value is read for that one entity through the catalog resource model's
 * getAttributeRawValue(), so the answer never depends on which attributes happened to be
 * loaded. The value is global-scoped, so the store id only matters if a merchant changes the
 * attribute's scope.
 *
 * VARIANTS FOLLOW THE PARENT. The cart guard sees the product the shopper adds — for a
 * configurable, bundle or grouped product that is the parent — so a flag on the parent
 * covers every option of it. A flag set only on a child simple is honoured when that simple
 * is added on its own. README "Known limitations" says so.
 *
 * Anything unreadable is "not quote-only" (QuoteOnlyRules::isFlagOn): a half-installed
 * attribute must not lock the catalogue.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\DataObject;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class ProductFlag
{
    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var array<string, bool> Per-request memo: a listing asks once per tile and per price.
     */
    private $memo = [];

    /**
     * @param ProductResource $productResource
     */
    public function __construct(ProductResource $productResource)
    {
        $this->productResource = $productResource;
    }

    /**
     * Whether the product is flagged quote-only.
     *
     * @param mixed $product A catalog product (any DataObject carrying an id).
     * @param int|null $storeId
     * @return bool
     */
    public function isQuoteOnly($product, ?int $storeId = null): bool
    {
        if (!$product instanceof DataObject) {
            return false;
        }

        if ($product->hasData(QuoteOnlyRules::PRODUCT_ATTRIBUTE)) {
            return QuoteOnlyRules::isFlagOn($product->getData(QuoteOnlyRules::PRODUCT_ATTRIBUTE));
        }

        return $this->isQuoteOnlyId((int) $product->getId(), $storeId);
    }

    /**
     * Whether the product with this id is flagged quote-only.
     *
     * @param int $productId
     * @param int|null $storeId
     * @return bool
     */
    public function isQuoteOnlyId(int $productId, ?int $storeId = null): bool
    {
        if ($productId < 1) {
            return false;
        }

        $key = $productId . '|' . (int) $storeId;
        if (!array_key_exists($key, $this->memo)) {
            try {
                $raw = $this->productResource->getAttributeRawValue(
                    $productId,
                    QuoteOnlyRules::PRODUCT_ATTRIBUTE,
                    (int) $storeId
                );
            } catch (\Exception $e) {
                $raw = null;
            }
            $this->memo[$key] = QuoteOnlyRules::isFlagOn($raw);
        }

        return $this->memo[$key];
    }
}
