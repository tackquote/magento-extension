<?php
/**
 * The catalog-visibility interception points: the menu, its block-cache key, the category
 * page, the product page, listings (search request binding) and Add to Cart (salability).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Catalog;

use Magento\Catalog\Controller\Category\View;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as FulltextCollection;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Data\Tree;
use Magento\Framework\Data\Tree\Node;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Theme\Block\Html\Topmenu;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;
use TackQuote\Quotes\Observer\HiddenProductNotSalable;
use TackQuote\Quotes\Plugin\Catalog\CategoryViewVisibility;
use TackQuote\Quotes\Plugin\Catalog\LayerVisibility;
use TackQuote\Quotes\Plugin\Catalog\ProductPageVisibility;
use TackQuote\Quotes\Plugin\Catalog\TopmenuVisibility;

/**
 * @covers \TackQuote\Quotes\Plugin\Catalog\TopmenuVisibility
 * @covers \TackQuote\Quotes\Plugin\Catalog\CategoryViewVisibility
 * @covers \TackQuote\Quotes\Plugin\Catalog\ProductPageVisibility
 * @covers \TackQuote\Quotes\Plugin\Catalog\LayerVisibility
 * @covers \TackQuote\Quotes\Observer\HiddenProductNotSalable
 */
class CatalogVisibilityPluginsTest extends TestCase
{
    /**
     * @param int[] $hiddenCategories
     * @param int[] $hiddenProducts
     * @return CatalogVisibility&MockObject
     */
    private function visibility(array $hiddenCategories, array $hiddenProducts = []): CatalogVisibility
    {
        $visibility = $this->createMock(CatalogVisibility::class);
        $visibility->method('hiddenCategoryIds')->willReturn($hiddenCategories);
        $visibility->method('isCategoryHidden')->willReturnCallback(static function ($id) use ($hiddenCategories) {
            return in_array($id, $hiddenCategories, true);
        });
        $visibility->method('isProductHidden')->willReturnCallback(static function ($id) use ($hiddenProducts) {
            return in_array($id, $hiddenProducts, true);
        });
        $visibility->method('varyValue')->willReturn($hiddenCategories === [] ? '' : 'h4sh');

        return $visibility;
    }

    /**
     * The tree Magento_Catalog's menu plugin builds: root > 10 > 12, root > 20.
     *
     * @return array{0: Node, 1: Node}
     */
    private function menu(): array
    {
        $tree = new Tree();
        $root = new Node([], 'root', $tree);
        $make = static function (int $id, Node $parent) use ($tree): Node {
            $node = new Node(['id' => 'category-node-' . $id, 'name' => 'C' . $id], 'id', $tree, $parent);
            $parent->addChild($node);

            return $node;
        };
        $ten = $make(10, $root);
        $make(12, $ten);
        $make(20, $root);

        return [$root, $ten];
    }

    /**
     * Only getMenu() is doubled: doubling every method reflects AbstractBlock::formatDate()'s
     * IntlDateFormatter default, which needs ext-intl.
     *
     * @return Topmenu&MockObject
     */
    private function topmenu(): Topmenu
    {
        return $this->getMockBuilder(Topmenu::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMenu'])
            ->getMock();
    }

    public function testAHiddenCategoryLeavesTheMenuWithItsChildren(): void
    {
        [$root] = $this->menu();
        $block = $this->topmenu();
        $block->method('getMenu')->willReturn($root);

        (new TopmenuVisibility($this->visibility([10, 12])))->beforeGetHtml($block);

        self::assertSame(['category-node-20'], array_keys($root->getAllChildNodes()));
    }

    public function testNothingHiddenLeavesTheMenuAlone(): void
    {
        [$root] = $this->menu();
        $block = $this->topmenu();
        $block->expects(self::never())->method('getMenu');

        (new TopmenuVisibility($this->visibility([])))->beforeGetHtml($block);

        self::assertCount(3, $root->getAllChildNodes());
    }

    public function testEachHiddenSetHasItsOwnCachedMenu(): void
    {
        $block = $this->topmenu();
        $key = ['BLOCK_TPL', 'default'];

        self::assertSame(
            $key + ['tackquote_hidden_categories' => 'h4sh'],
            (new TopmenuVisibility($this->visibility([10])))->afterGetCacheKeyInfo($block, $key)
        );
        self::assertSame($key, (new TopmenuVisibility($this->visibility([])))->afterGetCacheKeyInfo($block, $key));
    }

    private function categoryPage(CatalogVisibility $visibility, int $categoryId, ?Forward $forward, bool $expectProceed)
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn((string) $categoryId);
        $controller = $this->createMock(View::class);
        $controller->method('getRequest')->willReturn($request);
        $factory = $this->createMock(ForwardFactory::class);
        if ($forward !== null) {
            $factory->method('create')->willReturn($forward);
        }
        $proceeded = false;
        $out = (new CategoryViewVisibility($visibility, $factory))->aroundExecute(
            $controller,
            static function () use (&$proceeded) {
                $proceeded = true;

                return 'page';
            }
        );
        self::assertSame($expectProceed, $proceeded);

        return $out;
    }

    public function testAHiddenCategoryPageForwardsToNoroute(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects(self::once())->method('forward')->with('noroute')->willReturnSelf();

        self::assertSame($forward, $this->categoryPage($this->visibility([12]), 12, $forward, false));
    }

    public function testAVisibleCategoryPageRuns(): void
    {
        self::assertSame('page', $this->categoryPage($this->visibility([12]), 13, null, true));
    }

    public function testAProductInAHiddenCategoryIsNotLoaded(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(7);
        $plugin = new ProductPageVisibility($this->visibility([12], [7]));

        self::assertFalse($plugin->afterInitProduct($this->createMock(ProductHelper::class), $product));
    }

    public function testAVisibleProductLoadsAndFalseStaysFalse(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(8);
        $plugin = new ProductPageVisibility($this->visibility([12], [7]));
        $helper = $this->createMock(ProductHelper::class);

        self::assertSame($product, $plugin->afterInitProduct($helper, $product));
        self::assertFalse($plugin->afterInitProduct($helper, false));
    }

    public function testListingsBindTheHiddenSetToTheSearchRequest(): void
    {
        $collection = $this->createMock(FulltextCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('tackquote_hidden_category_ids', [12, 13]);
        $layer = $this->createMock(Layer::class);

        $out = (new LayerVisibility($this->visibility([12, 13]), $this->createMock(LoggerInterface::class)))
            ->afterPrepareProductCollection($layer, $layer, $collection);

        self::assertSame($layer, $out);
    }

    public function testListingsWithNothingHiddenOrAnotherCollectionAreUntouched(): void
    {
        $fulltext = $this->createMock(FulltextCollection::class);
        $fulltext->expects(self::never())->method('addFieldToFilter');
        $other = $this->createMock(ProductCollection::class);
        $other->expects(self::never())->method('addFieldToFilter');
        $layer = $this->createMock(Layer::class);
        $logger = $this->createMock(LoggerInterface::class);

        (new LayerVisibility($this->visibility([]), $logger))->afterPrepareProductCollection($layer, $layer, $fulltext);
        (new LayerVisibility($this->visibility([12]), $logger))->afterPrepareProductCollection($layer, $layer, $other);
    }

    public function testAnAlreadySearchedCollectionIsLoggedNotFatal(): void
    {
        $collection = $this->createMock(FulltextCollection::class);
        $collection->method('addFieldToFilter')->willThrowException(new \RuntimeException('Illegal state'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $layer = $this->createMock(Layer::class);

        (new LayerVisibility($this->visibility([12]), $logger))->afterPrepareProductCollection($layer, $layer, $collection);
    }

    private function salable(CatalogVisibility $visibility, int $productId, bool $salable): bool
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);
        $result = new DataObject(['product' => $product, 'is_salable' => $salable]);
        $event = new Event(['product' => $product, 'salable' => $result]);
        (new HiddenProductNotSalable($visibility))->execute(new Observer(['event' => $event]));

        return (bool) $result->getData('is_salable');
    }

    public function testAHiddenProductCannotBeBought(): void
    {
        $visibility = $this->visibility([12], [7]);

        self::assertFalse($this->salable($visibility, 7, true));
        self::assertTrue($this->salable($visibility, 8, true));
        self::assertFalse($this->salable($visibility, 8, false), 'never makes anything salable');
    }
}
