<?php
/**
 * The page-cache vary value for buyer-group catalog visibility, and the warm-up that decides it
 * before the session is depersonalised.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\PageCache;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;
use TackQuote\Quotes\Plugin\PageCache\BuyerGroupContext;
use TackQuote\Quotes\Plugin\PageCache\BuyerGroupWarmup;

/**
 * @covers \TackQuote\Quotes\Plugin\PageCache\BuyerGroupContext
 * @covers \TackQuote\Quotes\Plugin\PageCache\BuyerGroupWarmup
 */
class BuyerGroupContextTest extends TestCase
{
    public function testAVisitorWithHiddenCategoriesGetsTheirOwnVariationWithAnEmptyDefault(): void
    {
        $visibility = $this->createMock(CatalogVisibility::class);
        $visibility->method('varyValue')->willReturn('abc123');
        $context = $this->createMock(HttpContext::class);
        $context->expects(self::once())->method('setValue')->with('tackquote_buyer_group', 'abc123', '');

        (new BuyerGroupContext($visibility))->beforeGetVaryString($context);
    }

    public function testNothingHiddenLeavesTheCacheKeyAsItWas(): void
    {
        $visibility = $this->createMock(CatalogVisibility::class);
        $visibility->method('varyValue')->willReturn('');
        $context = $this->createMock(HttpContext::class);
        $context->expects(self::never())->method('setValue');

        (new BuyerGroupContext($visibility))->beforeGetVaryString($context);
    }

    public function testTheWarmupResolvesTheHiddenSetBeforeTheActionRuns(): void
    {
        $visibility = $this->createMock(CatalogVisibility::class);
        $visibility->expects(self::once())->method('hiddenCategoryIds')->willReturn([]);

        (new BuyerGroupWarmup($visibility))->beforeExecute($this->createMock(ActionInterface::class));
    }
}
