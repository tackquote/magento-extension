<?php
/**
 * Pins the per-product attribute's definition: the parts the storefront depends on.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Setup;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyRules;
use TackQuote\Quotes\Setup\Patch\Data\AddQuoteOnlyProductAttribute;

/**
 * @covers \TackQuote\Quotes\Setup\Patch\Data\AddQuoteOnlyProductAttribute
 */
class AddQuoteOnlyProductAttributeTest extends TestCase
{
    public function testItIsAYesNoDefaultingToNo(): void
    {
        $definition = AddQuoteOnlyProductAttribute::definition();

        self::assertSame('int', $definition['type']);
        self::assertSame('boolean', $definition['input']);
        self::assertSame(Boolean::class, $definition['source']);
        self::assertSame('0', $definition['default'], 'an upgrade must not make any product quote-only');
        self::assertFalse($definition['required']);
    }

    public function testItIsVisibleInTheProductFormAndLoadedOnListings(): void
    {
        $definition = AddQuoteOnlyProductAttribute::definition();

        self::assertTrue($definition['visible']);
        self::assertSame(AddQuoteOnlyProductAttribute::GROUP, $definition['group']);
        self::assertTrue(
            $definition['used_in_product_listing'],
            'listing tiles need the flag loaded, or every tile costs a query'
        );
        self::assertFalse($definition['visible_on_front']);
        self::assertSame(ScopedAttributeInterface::SCOPE_GLOBAL, $definition['global']);
    }

    public function testApplyAddsExactlyThatAttributeToProducts(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);

        $eav = $this->createMock(EavSetup::class);
        $eav->expects(self::once())->method('addAttribute')->with(
            Product::ENTITY,
            QuoteOnlyRules::PRODUCT_ATTRIBUTE,
            AddQuoteOnlyProductAttribute::definition()
        );
        $factory = $this->getMockBuilder(EavSetupFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($eav);

        (new AddQuoteOnlyProductAttribute($setup, $factory))->apply();
    }

    public function testTheAttributeCodeIsTheOneTheStorefrontReads(): void
    {
        self::assertSame('tackquote_quote_only', QuoteOnlyRules::PRODUCT_ATTRIBUTE);
    }
}
