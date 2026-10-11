<?php
/**
 * Catalog visibility for the visitor of a request: off costs nothing, the hidden set includes
 * children, products follow their categories, and the vary value names the hidden set.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\Audience;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;
use TackQuote\Quotes\Model\Groups\CategoryIndex;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;

/**
 * @covers \TackQuote\Quotes\Model\Groups\CatalogVisibility
 */
class CatalogVisibilityTest extends TestCase
{
    /** @var GroupRulesConfig&MockObject */
    private $config;

    /** @var Audience&MockObject */
    private $audience;

    /** @var CategoryIndex&MockObject */
    private $index;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->audience = $this->createMock(Audience::class);
        $this->index = $this->createMock(CategoryIndex::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function visibility(): CatalogVisibility
    {
        return new CatalogVisibility($this->config, $this->audience, $this->index, $this->storeManager);
    }

    private function active(array $map, BuyerGroupAnswer $answer, bool $hideWhenUnknown = false): void
    {
        $this->config->method('isCatalogVisibilityActive')->willReturn(true);
        $this->config->method('getCategoryMap')->willReturn($map);
        $this->config->method('hideCatalogWhenUnknown')->willReturn($hideWhenUnknown);
        $this->audience->method('current')->willReturn($answer);
    }

    public function testOffAsksNobodyAndHidesNothing(): void
    {
        $this->config->method('isCatalogVisibilityActive')->willReturn(false);
        $this->audience->expects(self::never())->method('current');
        $this->index->expects(self::never())->method('withDescendants');

        $visibility = $this->visibility();
        self::assertSame([], $visibility->hiddenCategoryIds());
        self::assertFalse($visibility->isProductHidden(5));
        self::assertSame('', $visibility->varyValue());
    }

    public function testAHiddenCategoryTakesItsChildrenWithIt(): void
    {
        $this->active([12 => ['@GUESTS']], new BuyerGroupAnswer(BuyerGroupAnswer::GUEST));
        $this->index->expects(self::once())->method('withDescendants')->with([12])->willReturn([12, 13, 40]);

        $visibility = $this->visibility();
        self::assertSame([12, 13, 40], $visibility->hiddenCategoryIds());
        self::assertTrue($visibility->isCategoryHidden(40));
        self::assertFalse($visibility->isCategoryHidden(41));
        // Memoised: asked once.
        $visibility->hiddenCategoryIds();
    }

    public function testAGroupNotNamedSeesEverything(): void
    {
        $this->active([12 => ['@GUESTS']], new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));
        $this->index->expects(self::never())->method('withDescendants');

        self::assertSame([], $this->visibility()->hiddenCategoryIds());
    }

    public function testAProductInAHiddenCategoryIsHidden(): void
    {
        $this->active([12 => ['RETAIL']], new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'RETAIL'));
        $this->index->method('withDescendants')->willReturn([12]);
        $this->index->expects(self::once())->method('productIdsIn')->with([12])->willReturn([7, 9]);

        $visibility = $this->visibility();
        self::assertTrue($visibility->isProductHidden(7));
        self::assertFalse($visibility->isProductHidden(8));
        self::assertFalse($visibility->isProductHidden(0));
    }

    public function testTheVaryValueNamesTheHiddenSetNotTheGroup(): void
    {
        $this->active([12 => ['RETAIL', 'SILVER']], new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'RETAIL'));
        $this->index->method('withDescendants')->willReturn([12, 13]);

        $value = $this->visibility()->varyValue();

        self::assertSame(16, strlen($value));
        self::assertStringNotContainsString('RETAIL', $value);
        self::assertSame(substr(hash('sha256', '12,13'), 0, 16), $value);
    }

    public function testAnUnknownGroupHidesOnlyUnderTheStrictReading(): void
    {
        $this->active([12 => ['RETAIL'], 40 => ['GOLD']], new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE), true);
        $this->index->expects(self::once())->method('withDescendants')->with([12, 40])->willReturn([12, 40]);

        self::assertSame([12, 40], $this->visibility()->hiddenCategoryIds());
    }
}
