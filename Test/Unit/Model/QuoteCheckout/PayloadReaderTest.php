<?php
/**
 * The exchange answer is read exactly, or not at all.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\PayloadReader;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\PayloadReader
 */
class PayloadReaderTest extends TestCase
{
    /**
     * The server's shape (tack MagentoQuoteCheckoutPayload).
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'quoteRef' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
            'quoteNumber' => 'TK-2026-000123',
            'currency' => 'USD',
            'buyerEmail' => 'buyer@example.test',
            'poNumber' => 'PO-9',
            'lines' => [
                ['sku' => 'SKU-A', 'name' => 'Alpha', 'quantity' => 3, 'unitPrice' => '10.00', 'lineTotal' => '30.00'],
                [
                    'sku' => 'SKU-B',
                    'name' => '<b>Beta</b>',
                    'quantity' => 2,
                    'unitPrice' => '5.5',
                    'lineTotal' => '11.00'
                ],
            ],
            'goodsTotal' => '41.00',
        ], $over);
    }

    public function testTheServersPayloadIsRead(): void
    {
        $out = (new PayloadReader())->read($this->payload());

        self::assertSame('tqco_AAAAAAAAAAAAAAAAAAAAAA', $out['quoteRef']);
        self::assertSame('USD', $out['currency']);
        self::assertSame('buyer@example.test', $out['buyerEmail']);
        self::assertSame(
            [
                ['sku' => 'SKU-A', 'name' => 'Alpha', 'quantity' => 3, 'unitPrice' => '10.00'],
                ['sku' => 'SKU-B', 'name' => 'Beta', 'quantity' => 2, 'unitPrice' => '5.5'],
            ],
            $out['lines']
        );
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function refused(): array
    {
        $line = ['sku' => 'SKU-A', 'name' => 'A', 'quantity' => 1, 'unitPrice' => '1.00'];

        return [
            'a quote number as the reference' => [['quoteRef' => 'TK-2026-000123']],
            'no currency' => [['currency' => '']],
            'no lines' => [['lines' => []]],
            'a fractional quantity' => [['lines' => [array_merge($line, ['quantity' => 1.5])]]],
            'a numeric-string quantity' => [['lines' => [array_merge($line, ['quantity' => '1'])]]],
            'a float price' => [['lines' => [array_merge($line, ['unitPrice' => 1.0])]]],
            'five decimals' => [['lines' => [array_merge($line, ['unitPrice' => '1.00001'])]]],
            'a negative price' => [['lines' => [array_merge($line, ['unitPrice' => '-1'])]]],
            'no SKU' => [['lines' => [array_merge($line, ['sku' => ' '])]]],
            'one SKU twice' => [['lines' => [$line, array_merge($line, ['sku' => 'sku-a'])]]],
            'too many lines' => [['lines' => array_map(
                static function (int $i) use ($line): array {
                    return array_merge($line, ['sku' => 'S' . $i]);
                },
                range(1, 251)
            )]],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAnythingNotExactIsRefusedWhole(array $over): void
    {
        self::assertNull((new PayloadReader())->read($this->payload($over)));
    }

    public function testAnUnusableEmailIsDroppedNotTheQuote(): void
    {
        $out = (new PayloadReader())->read($this->payload(['buyerEmail' => 'not an email']));

        self::assertNotNull($out);
        self::assertSame('', $out['buyerEmail']);
        self::assertNull((new PayloadReader())->read('not json'));
    }
}
