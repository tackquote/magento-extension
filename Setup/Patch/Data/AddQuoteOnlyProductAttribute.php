<?php
/**
 * Adds the per-product quote-only switch: product attribute `tackquote_quote_only`.
 *
 * A Yes/No attribute ("Catalog Input Type for Store Owner: Yes/No", i.e. `input` boolean
 * with the Boolean source model), default No, in the "Product Details" group of every
 * attribute set so it shows in the admin product form beside Enable Product and Visibility.
 * When it is Yes the product is quote-only for every storefront visitor even while the store
 * itself is in normal mode — no Add to Cart, quote buttons shown, cart and checkout refuse it
 * — which is the WooCommerce plugin's per-product "Quote only" checkbox (1.10.0+). The
 * storefront reads it through Model\QuoteOnly\ProductFlag.
 *
 * SOURCE. A data patch implementing DataPatchInterface, with EavSetup::addAttribute() and the
 * option keys from Adobe's reference table "Add product EAV attribute options"
 * (developer.adobe.com/commerce/php/development/components/attributes): `group` names the
 * attribute group, and `global` defaults to 1 (global scope).
 *
 * OPTIONS THAT MATTER, and why:
 *   - global (SCOPE_GLOBAL): a selling policy, not a translation; one value per product keeps
 *     cart and checkout from disagreeing across store views.
 *   - used_in_product_listing: loaded with the product collection, so category and search
 *     tiles know the flag without one query per tile.
 *   - visible_on_front = false: it is not shown in the "More Information" tab.
 *   - is_used_in_grid / is_filterable_in_grid: the product grid can list the quote-only
 *     products.
 *
 * UNVERIFIED on a store: the field's exact position in the product form, and the grid
 * column. The PR lists the manual check.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class AddQuoteOnlyProductAttribute implements DataPatchInterface, PatchRevertableInterface
{
    /** The admin product-form group the field is placed in. */
    public const GROUP = 'Product Details';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EavSetupFactory $eavSetupFactory)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * The attribute definition, public so the unit suite can pin it.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'type' => 'int',
            'label' => 'Quote only (TackQuote)',
            'input' => 'boolean',
            'source' => Boolean::class,
            'default' => '0',
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'group' => self::GROUP,
            'sort_order' => 200,
            'visible' => true,
            'required' => false,
            'user_defined' => true,
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'visible_on_front' => false,
            'used_in_product_listing' => true,
            'unique' => false,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => true,
            'note' => 'Yes hides Add to Cart for this product and takes quote requests instead, '
                . 'even when the store is not quote-only. Set it on the parent of a configurable, '
                . 'bundle or grouped product; its options follow it.',
        ];
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(Product::ENTITY, QuoteOnlyRules::PRODUCT_ATTRIBUTE, self::definition());

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return void
     */
    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Product::ENTITY, QuoteOnlyRules::PRODUCT_ATTRIBUTE);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }
}
