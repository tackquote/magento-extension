<?php
/**
 * The custom price on each cart line: set only from a confirmed TackQuote price, never on a
 * line that is not ours to price, reset when it no longer applies, and never with super mode.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;
use TackQuote\Quotes\Model\Enforce\Eligibility;
use TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch;
use TackQuote\Quotes\Model\Enforce\PriceResolver;
use TackQuote\Quotes\Model\QuoteOnlyMode;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\CustomPriceApplier
 */
class CustomPriceApplierTest extends TestCase
{
    private const BUYER = ['storeId' => 1, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD'];

    /** @var Eligibility&MockObject */
    private $eligibility;

    /** @var PriceEnforcementSwitch&MockObject */
    private $switch;

    /** @var QuoteOnlyMode&MockObject */
    private $quoteOnlyMode;

    /** @var PriceResolver&MockObject */
    private $resolver;

    /** @var array{prices: array<string, float>, fallback: bool} */
    private $resolution;

    /** @var array<int, array> */
    private $resolveCalls = [];

    protected function setUp(): void
    {
        $this->resolution = ['prices' => [AnswerMapper::lineKey('A', 2) => 7.5], 'fallback' => false];
        $this->eligibility = $this->createMock(Eligibility::class);
        $this->eligibility->method('buyer')->willReturn(self::BUYER);
        $this->switch = $this->createMock(PriceEnforcementSwitch::class);
        $this->switch->method('isOn')->willReturn(true);
        $this->quoteOnlyMode = $this->createMock(QuoteOnlyMode::class);
        $this->resolver = $this->createMock(PriceResolver::class);
        $this->resolver->method('resolve')->willReturnCallback(function (array $buyer, array $lines, bool $fresh) {
            $this->resolveCalls[] = [$buyer, $lines, $fresh];

            return $this->resolution;
        });
    }

    private function applier(): CustomPriceApplier
    {
        return new CustomPriceApplier(
            $this->eligibility,
            $this->switch,
            $this->quoteOnlyMode,
            $this->resolver,
            new Json()
        );
    }

    /**
     * A cart line backed by real DataObject storage.
     */
    private function item(array $data = [], ?Item $parent = null): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'getProduct'])
            ->getMock();
        $item->method('getParentItem')->willReturn($parent);
        $item->expects(self::never())->method('getProduct');
        $item->setData(array_merge(['product_type' => 'simple', 'sku' => 'A', 'qty' => 2, 'product_id' => 5], $data));

        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function quote(array $items): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'getStoreId'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }

    private static function marker(Item $item): ?float
    {
        $data = json_decode((string) $item->getData('additional_data'), true);

        return is_array($data) && isset(
            $data[CustomPriceApplier::MARKER]
        ) ? (float) $data[CustomPriceApplier::MARKER] : null;
    }

    public function testAConfirmedAccountPriceBecomesTheLinesCustomPrice(): void
    {
        $line = $this->item();
        $quote = $this->quote([$line]);

        $changed = $this->applier()->apply($quote);

        self::assertTrue($changed);
        self::assertSame(7.5, $line->getData('custom_price'));
        self::assertSame(7.5, $line->getData('original_custom_price'));
        self::assertSame(7.5, $line->getCalculationPrice(), 'what Magento prices the line from');
        self::assertSame(7.5, self::marker($line));
        self::assertFalse($quote->getData(CustomPriceApplier::FALLBACK_FLAG));
        self::assertNull($quote->getData('is_super_mode'), 'super mode would switch stock validation off');
        self::assertSame([[self::BUYER, [['sku' => 'A', 'quantity' => 2]], false]], $this->resolveCalls);
    }

    public function testFreshIsPassedThrough(): void
    {
        $this->applier()->apply($this->quote([$this->item()]), true);

        self::assertTrue($this->resolveCalls[0][2]);
    }

    public function testApplyingTheSamePriceAgainChangesNothing(): void
    {
        $line = $this->item();
        $quote = $this->quote([$line]);
        $this->applier()->apply($quote);

        self::assertFalse($this->applier()->apply($quote));
        self::assertSame(7.5, $line->getData('custom_price'));
    }

    public function testANewPriceReplacesOurOldOne(): void
    {
        $line = $this->item();
        $quote = $this->quote([$line]);
        $this->applier()->apply($quote);
        $this->resolution = ['prices' => [AnswerMapper::lineKey('A', 2) => 6.0], 'fallback' => false];

        self::assertTrue($this->applier()->apply($quote));
        self::assertSame(6.0, $line->getData('custom_price'));
        self::assertSame(6.0, self::marker($line));
    }

    public function testOurPriceIsResetWhenTackQuoteNoLongerPricesTheLine(): void
    {
        $line = $this->item(['additional_data' => '{"other":"kept"}']);
        $quote = $this->quote([$line]);
        $this->applier()->apply($quote);
        $this->resolution = ['prices' => [], 'fallback' => false];

        self::assertTrue($this->applier()->apply($quote));
        self::assertNull($line->getData('custom_price'));
        self::assertNull($line->getData('original_custom_price'));
        self::assertNull(self::marker($line));
        self::assertSame(['other' => 'kept'], json_decode((string) $line->getData('additional_data'), true));
    }

    public function testAFailureResetsOurPriceAndRaisesTheFallbackFlag(): void
    {
        $line = $this->item();
        $quote = $this->quote([$line]);
        $this->applier()->apply($quote);
        $this->resolution = ['prices' => [], 'fallback' => true];

        $this->applier()->apply($quote, true);

        self::assertNull($line->getData('custom_price'), 'the store price, never the old TackQuote price');
        self::assertTrue($quote->getData(CustomPriceApplier::FALLBACK_FLAG));
    }

    public function testOurPriceIsResetWhenTheSwitchIsTurnedOff(): void
    {
        $line = $this->item();
        $quote = $this->quote([$line]);
        $this->applier()->apply($quote);

        $switch = $this->createMock(PriceEnforcementSwitch::class);
        $switch->method('isOn')->willReturn(false);
        $off = new CustomPriceApplier($this->eligibility, $switch, $this->quoteOnlyMode, $this->resolver, new Json());
        $this->resolveCalls = [];

        self::assertTrue($off->apply($quote));
        self::assertNull($line->getData('custom_price'));
        self::assertSame([], $this->resolveCalls, 'nothing is asked while the switch is off');
    }

    public function testAnIneligibleCallerIsNeverCharged(): void
    {
        $eligibility = $this->createMock(Eligibility::class);
        $eligibility->method('buyer')->willReturn(null);
        $line = $this->item();

        (new CustomPriceApplier($eligibility, $this->switch, $this->quoteOnlyMode, $this->resolver, new Json()))
            ->apply($this->quote([$line]));

        self::assertNull($line->getData('custom_price'));
        self::assertSame([], $this->resolveCalls);
    }

    public function testAHiddenPriceIsNeverCharged(): void
    {
        $this->quoteOnlyMode->method('shouldHidePrices')->willReturn(true);
        $line = $this->item();

        $this->applier()->apply($this->quote([$line]));

        self::assertNull($line->getData('custom_price'));
        self::assertSame([], $this->resolveCalls);
    }

    public function testAQuoteOnlyProductIsNotPriced(): void
    {
        $this->quoteOnlyMode->method('isProductIdQuoteOnly')->with(5, 1)->willReturn(true);
        $line = $this->item();

        $this->applier()->apply($this->quote([$line]));

        self::assertNull($line->getData('custom_price'));
    }

    public function testSomeoneElsesCustomPriceIsLeftAlone(): void
    {
        $line = $this->item(['custom_price' => 3.0]);

        self::assertFalse($this->applier()->apply($this->quote([$line])));
        self::assertSame(3.0, $line->getData('custom_price'));
        self::assertNull(self::marker($line));
    }

    public function testAMarkerThatNoLongerMatchesThePriceIsNotOurs(): void
    {
        // Another extension changed the price after we set it: it is theirs now.
        $line = $this->item(['custom_price' => 4.0, 'additional_data' => '{"tackquote_account_price":7.5}']);
        $this->resolution = ['prices' => [], 'fallback' => false];

        self::assertFalse($this->applier()->apply($this->quote([$line])));
        self::assertSame(4.0, $line->getData('custom_price'));
    }

    public function testAdditionalDataThatIsNotOursToWriteKeepsTheStorePrice(): void
    {
        $plain = $this->item(['additional_data' => 'serialized:legacy']);
        $list = $this->item(['additional_data' => '[1,2]']);

        $this->applier()->apply($this->quote([$plain, $list]));

        self::assertNull($plain->getData('custom_price'));
        self::assertSame('serialized:legacy', $plain->getData('additional_data'));
        self::assertNull($list->getData('custom_price'));
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function unpricedLines(): array
    {
        return [
            'configurable' => [['product_type' => 'configurable']],
            'bundle' => [['product_type' => 'bundle']],
            'line with children' => [['has_children' => true]],
            'fractional quantity' => [['qty' => 2.5]],
            'no sku' => [['sku' => '']],
            'no type' => [['product_type' => null]],
        ];
    }

    /**
     * @dataProvider unpricedLines
     */
    public function testLinesThatAreNotPricedAsThemselvesAreNotAsked(array $data): void
    {
        $line = $this->item($data);

        $this->applier()->apply($this->quote([$line]));

        self::assertNull($line->getData('custom_price'));
        self::assertSame([], $this->resolveCalls);
    }

    public function testAChildLineIsNotPriced(): void
    {
        $parent = $this->item(['product_type' => 'configurable']);
        $child = $this->item([], $parent);

        $this->applier()->apply($this->quote([$child]));

        self::assertNull($child->getData('custom_price'));
    }

    public function testAPriceAtAnotherQuantityIsNotUsed(): void
    {
        $line = $this->item(['qty' => 3]);

        $this->applier()->apply($this->quote([$line]));

        self::assertNull($line->getData('custom_price'), 'a quantity break exists only at its quantity');
    }

    public function testAnAcceptedQuoteLineIsNeverAskedAboutRepricedOrCleared(): void
    {
        // Lane MG-QUOTE-CHECKOUT-LINK: the quote marker is someone else's price, even where
        // account prices would apply (and even where they would be cleared).
        $quoted = json_encode([
            'tackquote_quote_ref' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
            'tackquote_quoted_price' => 9.25,
        ]);
        $line = $this->item(['custom_price' => 9.25, 'original_custom_price' => 9.25, 'additional_data' => $quoted]);

        self::assertFalse($this->applier()->apply($this->quote([$line])));
        self::assertSame(9.25, $line->getData('custom_price'));
        self::assertSame($quoted, $line->getData('additional_data'));
        self::assertSame([], $this->resolveCalls, 'a quoted line is never priced by TackQuote');

        $switch = $this->createMock(PriceEnforcementSwitch::class);
        $switch->method('isOn')->willReturn(false);
        (new CustomPriceApplier($this->eligibility, $switch, $this->quoteOnlyMode, $this->resolver, new Json()))
            ->apply($this->quote([$line]));
        self::assertSame(9.25, $line->getData('custom_price'), 'never cleared when prices are off');
    }
}
