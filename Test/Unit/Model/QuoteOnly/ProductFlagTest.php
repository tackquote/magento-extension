<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteOnly;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnly\ProductFlag;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnly\ProductFlag
 */
class ProductFlagTest extends TestCase
{
    /** @var ProductResource&MockObject */
    private $resource;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ProductResource::class);
    }

    private function flag(): ProductFlag
    {
        return new ProductFlag($this->resource);
    }

    public function testALoadedValueIsUsedWithoutAQuery(): void
    {
        $this->resource->expects(self::never())->method('getAttributeRawValue');

        self::assertTrue($this->flag()->isQuoteOnly(new DataObject(['id' => 7, 'tackquote_quote_only' => '1'])));
        self::assertFalse($this->flag()->isQuoteOnly(new DataObject(['id' => 8, 'tackquote_quote_only' => '0'])));
    }

    public function testAProductLoadedWithoutTheAttributeIsReadFromTheDatabase(): void
    {
        $this->resource->expects(self::once())->method('getAttributeRawValue')
            ->with(7, 'tackquote_quote_only', 2)
            ->willReturn('1');

        self::assertTrue($this->flag()->isQuoteOnly(new DataObject(['id' => 7]), 2));
    }

    public function testTheLookupIsMemoisedPerProductAndStore(): void
    {
        $this->resource->expects(self::once())->method('getAttributeRawValue')->willReturn('1');
        $flag = $this->flag();

        self::assertTrue($flag->isQuoteOnlyId(7));
        self::assertTrue($flag->isQuoteOnlyId(7));
    }

    public function testAMissingAttributeOrAFailingReadNeverLocksTheProduct(): void
    {
        $this->resource->method('getAttributeRawValue')->willReturnCallback(static function (int $id) {
            if ($id === 1) {
                return false;
            }
            throw new \RuntimeException('no such attribute');
        });

        self::assertFalse($this->flag()->isQuoteOnlyId(1));
        self::assertFalse($this->flag()->isQuoteOnlyId(2));
    }

    public function testNoProductOrNoIdIsNotQuoteOnly(): void
    {
        $this->resource->expects(self::never())->method('getAttributeRawValue');

        self::assertFalse($this->flag()->isQuoteOnly(null));
        self::assertFalse($this->flag()->isQuoteOnly(new \stdClass()));
        self::assertFalse($this->flag()->isQuoteOnly(new DataObject([])));
        self::assertFalse($this->flag()->isQuoteOnlyId(0));
    }
}
