<?php
/**
 * The two quote-checkout observers: the order report and "the quote leaves the cart whole".
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\CompletionReporter;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Observer\QuoteCheckoutLineRemoved;
use TackQuote\Quotes\Observer\QuoteCheckoutOrderReport;

/**
 * @covers \TackQuote\Quotes\Observer\QuoteCheckoutLineRemoved
 * @covers \TackQuote\Quotes\Observer\QuoteCheckoutOrderReport
 */
class QuoteCheckoutObserversTest extends TestCase
{
    /** @var int[] */
    private $removed = [];

    private function observer(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }

    private function line(int $id, ?string $additional, Quote $quote): Item
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $item->method('getQuote')->willReturn($quote);
        $item->setData(['id' => $id, 'additional_data' => $additional]);

        return $item;
    }

    public function testRemovingOneQuotedLineRemovesTheRestAndNothingElse(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'removeItem'])
            ->getMock();
        $marker = '{"tackquote_quote_ref":"tqco_AAAAAAAAAAAAAAAAAAAAAA"}';
        $removedLine = $this->line(1, $marker, $quote);
        $lines = [$this->line(2, $marker, $quote), $this->line(3, null, $quote)];
        $quote->method('getAllVisibleItems')->willReturn($lines);
        $quotedCart = new QuotedCart(new QuotedLine(new Json()));
        $quote->method('removeItem')->willReturnCallback(function ($id) use ($quote, $quotedCart) {
            // Core dispatches sales_quote_remove_item again for each; the flag stops recursion.
            self::assertTrue($quotedCart->isDropping());
            $this->removed[] = (int) $id;

            return $quote;
        });

        (new QuoteCheckoutLineRemoved($quotedCart, new QuotedLine(new Json())))
            ->execute($this->observer(['quote_item' => $removedLine]));

        self::assertSame([2], $this->removed);
    }

    public function testAnOrdinaryLineOrADropInProgressChangesNothing(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'removeItem'])
            ->getMock();
        $quote->expects(self::never())->method('removeItem');
        $quotedCart = new QuotedCart(new QuotedLine(new Json()));
        $observer = new QuoteCheckoutLineRemoved($quotedCart, new QuotedLine(new Json()));

        $observer->execute($this->observer(['quote_item' => $this->line(1, null, $quote)]));
        $quotedCart->setDropping(true);
        $observer->execute($this->observer([
            'quote_item' => $this->line(1, '{"tackquote_quote_ref":"tqco_AAAAAAAAAAAAAAAAAAAAAA"}', $quote),
        ]));
        self::assertTrue(true);
    }

    public function testThePlacedOrderIsReported(): void
    {
        $order = $this->createMock(Order::class);
        $reporter = $this->createMock(CompletionReporter::class);
        $reporter->expects(self::once())->method('report')->with($order);

        (new QuoteCheckoutOrderReport($reporter))->execute($this->observer(['order' => $order]));
    }
}
