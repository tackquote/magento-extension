<?php
/**
 * Is this cart exactly the accepted quote? The one rule every exemption and refusal reads.
 *
 * Mutation proof (each turns a test here red):
 *   Q1 the line count is not compared          -> testARemovedLineBreaksTheQuote
 *   Q2 the quantity is not compared            -> testAChangedQuantityBreaksTheQuote
 *   Q3 the custom price is not compared        -> testAChangedPriceBreaksTheQuote
 *   Q4 the cart currency is not compared       -> testASwitchedCurrencyBreaksTheQuote
 *   Q5 an unmarked line is tolerated           -> testAnotherProductBesideTheQuoteBreaksIt
 *   Q6 two references are tolerated            -> testTwoQuotesInOneCartAreNotOneQuote
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\QuotedCart
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\QuotedLine
 */
class QuotedCartTest extends TestCase
{
    private const REF = 'tqco_AAAAAAAAAAAAAAAAAAAAAA';

    /** @var int[] */
    private $removed = [];

    private function cart(): QuotedCart
    {
        return new QuotedCart(new QuotedLine(new Json()));
    }

    /**
     * @param array $marker Overrides of the marker.
     * @param array $data Overrides of the line's own data.
     */
    private function line(int $id, array $marker = [], array $data = []): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem'])
            ->getMock();
        $item->method('getParentItem')->willReturn(null);
        $markerData = array_merge([
            QuotedLine::REF => self::REF,
            QuotedLine::NUMBER => 'TK-2026-000123',
            QuotedLine::PRICE => 10.0,
            QuotedLine::QTY => 3,
            QuotedLine::LINES => 2,
            QuotedLine::CURRENCY => 'USD',
        ], $marker);
        $item->setData(array_merge([
            'id' => $id,
            'qty' => 3,
            'custom_price' => 10.0,
            'additional_data' => json_encode($markerData),
        ], $data));

        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function quote(array $items, string $currency = 'USD'): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'removeItem'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('removeItem')->willReturnCallback(function ($id) use ($quote) {
            $this->removed[] = (int) $id;

            return $quote;
        });
        $quote->setData('base_currency_code', 'USD');
        $quote->setData('quote_currency_code', $currency);

        return $quote;
    }

    public function testTheAcceptedQuoteIsIntact(): void
    {
        $quote = $this->quote([$this->line(1), $this->line(2)]);

        self::assertTrue($this->cart()->isIntact($quote));
        self::assertTrue($this->cart()->hasQuotedLines($quote));
        self::assertSame(self::REF, $this->cart()->ref($quote));
    }

    public function testARemovedLineBreaksTheQuote(): void
    {
        self::assertFalse($this->cart()->isIntact($this->quote([$this->line(1)])));
    }

    public function testAChangedQuantityBreaksTheQuote(): void
    {
        self::assertFalse($this->cart()->isIntact($this->quote([$this->line(1), $this->line(2, [], ['qty' => 4])])));
    }

    public function testAChangedPriceBreaksTheQuote(): void
    {
        $lines = [$this->line(1), $this->line(2, [], ['custom_price' => 9.99])];
        self::assertFalse($this->cart()->isIntact($this->quote($lines)));
        $lines = [$this->line(1), $this->line(2, [], ['custom_price' => null])];
        self::assertFalse($this->cart()->isIntact($this->quote($lines)));
    }

    public function testASwitchedCurrencyBreaksTheQuote(): void
    {
        self::assertFalse($this->cart()->isIntact($this->quote([$this->line(1), $this->line(2)], 'EUR')));
    }

    public function testAnotherProductBesideTheQuoteBreaksIt(): void
    {
        $other = $this->line(3, [], ['additional_data' => null]);
        $quote = $this->quote([$this->line(1), $this->line(2), $other]);

        self::assertFalse($this->cart()->isIntact($quote));
        self::assertTrue($this->cart()->hasQuotedLines($quote));
    }

    public function testTwoQuotesInOneCartAreNotOneQuote(): void
    {
        $quote = $this->quote([$this->line(1), $this->line(2, [QuotedLine::REF => 'tqco_BBBBBBBBBBBBBBBBBBBBBB'])]);

        self::assertFalse($this->cart()->isIntact($quote));
    }

    public function testAnOrdinaryOrEmptyCartIsNoQuote(): void
    {
        $plain = $this->quote([$this->line(1, [], ['additional_data' => '{"other_module":1}'])]);

        self::assertFalse($this->cart()->isIntact($plain));
        self::assertFalse($this->cart()->hasQuotedLines($plain));
        self::assertFalse($this->cart()->isIntact($this->quote([])));
    }

    public function testADamagedMarkerStillCountsAsQuotedButNeverAsIntact(): void
    {
        $quote = $this->quote([$this->line(1, [QuotedLine::QTY => 'three']), $this->line(2)]);

        self::assertTrue($this->cart()->hasQuotedLines($quote));
        self::assertFalse($this->cart()->isIntact($quote));
    }

    public function testDroppingTakesOutOnlyTheQuotedLines(): void
    {
        $quote = $this->quote([$this->line(1), $this->line(7, [], ['additional_data' => null]), $this->line(2)]);
        $cart = $this->cart();

        self::assertTrue($cart->dropQuotedLines($quote));
        self::assertSame([1, 2], $this->removed);
        self::assertFalse($cart->isDropping(), 'the flag is reset afterwards');
    }

    public function testTheBuildingFlag(): void
    {
        $cart = $this->cart();
        self::assertFalse($cart->isBuilding());
        $cart->startBuilding();
        self::assertTrue($cart->isBuilding());
        $cart->stopBuilding();
        self::assertFalse($cart->isBuilding());
    }

    public function testStampingKeepsAnotherModulesKeysAndRefusesForeignData(): void
    {
        $line = new QuotedLine(new Json());
        $item = $this->line(1, [], ['additional_data' => '{"other_module":1}']);
        $marker = [
            'ref' => self::REF,
            'number' => 'TK-1',
            'price' => 2.5,
            'qty' => 1,
            'lines' => 1,
            'currency' => 'USD',
        ];

        self::assertTrue($line->stamp($item, $marker));
        $data = json_decode((string) $item->getData('additional_data'), true);
        self::assertSame(1, $data['other_module']);
        self::assertSame($marker, $line->read($item));

        $foreign = $this->line(2, [], ['additional_data' => 'a:1:{s:1:"x";i:1;}']);
        self::assertFalse($line->stamp($foreign, $marker));
        self::assertSame('a:1:{s:1:"x";i:1;}', $foreign->getData('additional_data'));
    }
}
