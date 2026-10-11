<?php
/**
 * The order an accepted-quote cart became is reported once per order, the same way every time,
 * and never breaks the order.
 *
 * Mutation proof:
 *   R1 report an order whose lines name two references   -> testTwoReferencesAreNotReported
 *   R2 let a failed report throw                          -> testAFailedReportNeverThrows
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\QuoteCheckout\CheckoutApiClient;
use TackQuote\Quotes\Model\QuoteCheckout\CompletionReporter;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\CompletionReporter
 */
class CompletionReporterTest extends TestCase
{
    private const REF = 'tqco_AAAAAAAAAAAAAAAAAAAAAA';

    /** @var array<int, array> */
    private $sent = [];

    private function marker(string $ref = self::REF): string
    {
        return (string) json_encode([
            QuotedLine::REF => $ref,
            QuotedLine::NUMBER => 'TK-2026-000123',
            QuotedLine::PRICE => 10.0,
            QuotedLine::QTY => 3,
            QuotedLine::LINES => 1,
            QuotedLine::CURRENCY => 'USD',
        ]);
    }

    /**
     * @param string[] $additional Each line's additional_data.
     */
    private function order(array $additional, string $method = 'checkmo', string $state = 'processing'): Order
    {
        $items = [];
        foreach ($additional as $data) {
            $item = $this->createMock(OrderItem::class);
            $item->method('getAdditionalData')->willReturn($data);
            $items[] = $item;
        }
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $order = $this->createMock(Order::class);
        $order->method('getItems')->willReturn($items);
        $order->method('getEntityId')->willReturn(901);
        $order->method('getIncrementId')->willReturn('000000901');
        $order->method('getState')->willReturn($state);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function reporter(array $answer = ['ok' => true, 'status' => 200]): CompletionReporter
    {
        $client = $this->createMock(CheckoutApiClient::class);
        $client->method('complete')->willReturnCallback(function (array $body, $storeId) use ($answer) {
            $this->sent[] = [$body, $storeId];
            if (isset($answer['throw'])) {
                throw new \RuntimeException('network');
            }

            return $answer;
        });

        return new CompletionReporter($client, new QuotedLine(new Json()), $this->createMock(LoggerInterface::class));
    }

    public function testTheReportNamesTheReferenceTheOrderAndItsState(): void
    {
        self::assertTrue($this->reporter()->report($this->order([$this->marker(), $this->marker()])));
        self::assertSame(
            [[
                'quoteRef' => self::REF,
                'orderId' => '901',
                'incrementId' => '000000901',
                'state' => 'processing',
                'paymentMethod' => 'checkmo',
            ], 1],
            $this->sent[0]
        );
    }

    public function testTheSameOrderIsReportedTheSameWayEveryTime(): void
    {
        // The server is idempotent on (reference, order): a repeat is harmless only if the
        // repeat is the same report.
        $reporter = $this->reporter();
        $order = $this->order([$this->marker()], 'tackquote_net_terms');
        $reporter->report($order);
        $reporter->report($order);

        self::assertCount(2, $this->sent);
        self::assertSame($this->sent[0], $this->sent[1]);
        self::assertSame('tackquote_net_terms', $this->sent[0][0]['paymentMethod']);
    }

    public function testAnOrdinaryOrderIsNotReported(): void
    {
        self::assertFalse($this->reporter()->report($this->order([null, '{"other":1}'])));
        self::assertSame([], $this->sent);
    }

    public function testTwoReferencesAreNotReported(): void
    {
        $order = $this->order([$this->marker(), $this->marker('tqco_BBBBBBBBBBBBBBBBBBBBBB')]);

        self::assertFalse($this->reporter()->report($order));
        self::assertSame([], $this->sent);
    }

    public function testAnUnknownStateIsLeftOut(): void
    {
        $this->reporter()->report($this->order([$this->marker()], 'checkmo', 'paid'));

        self::assertArrayNotHasKey('state', $this->sent[0][0]);
    }

    public function testAFailedReportNeverThrows(): void
    {
        self::assertFalse($this->reporter(['ok' => false, 'status' => 503])->report($this->order([$this->marker()])));
        self::assertFalse($this->reporter(['throw' => true])->report($this->order([$this->marker()])));
    }
}
