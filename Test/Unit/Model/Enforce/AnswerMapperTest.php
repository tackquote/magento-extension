<?php
/**
 * What may be charged from a resolve answer, what the currency read says, and which
 * order-total limits apply. Every rule fails closed.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\AnswerMapper
 */
class AnswerMapperTest extends TestCase
{
    private static function line(array $overrides = []): array
    {
        return array_merge(
            ['sku' => 'A-1', 'quantity' => 5, 'unitPrice' => 8.25, 'source' => 'price_book'],
            $overrides
        );
    }

    public function testAPriceBookPriceForAMatchedBuyerIsChargeableAtItsQuantity(): void
    {
        $prices = (new AnswerMapper())->chargeablePrices(['buyerMatched' => true, 'items' => [self::line()]]);

        self::assertSame([AnswerMapper::lineKey('A-1', 5) => 8.25], $prices);
    }

    public function testAnUnmatchedBuyerGetsNothingEvenFromAPriceBook(): void
    {
        // Without a matched buyer the price book is the tenant's default: a stranger's price.
        $mapper = new AnswerMapper();

        self::assertSame([], $mapper->chargeablePrices(['buyerMatched' => false, 'items' => [self::line()]]));
        self::assertSame([], $mapper->chargeablePrices(['buyerMatched' => 'true', 'items' => [self::line()]]));
        self::assertSame([], $mapper->chargeablePrices(['items' => [self::line()]]));
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function notChargeable(): array
    {
        return [
            'catalog list price' => [['source' => 'catalog']],
            'pricing rule (account-ness not on the wire)' => [['source' => 'pricing_rule']],
            'unpriced' => [['source' => 'unpriced', 'unitPrice' => null]],
            'unknown source' => [['source' => 'erp_live']],
            'null price' => [['unitPrice' => null]],
            'string price' => [['unitPrice' => '8.25']],
            'boolean price' => [['unitPrice' => true]],
            'negative price' => [['unitPrice' => -1]],
            'no sku' => [['sku' => '']],
            'quantity zero' => [['quantity' => 0]],
            'string quantity' => [['quantity' => '5']],
        ];
    }

    /**
     * @dataProvider notChargeable
     */
    public function testAnythingButAPriceBookNumberKeepsTheStorePrice(array $overrides): void
    {
        self::assertSame(
            [],
            (new AnswerMapper())->chargeablePrices(['buyerMatched' => true, 'items' => [self::line($overrides)]])
        );
    }

    public function testZeroIsARealPrice(): void
    {
        $prices = (new AnswerMapper())->chargeablePrices(
            ['buyerMatched' => true, 'items' => [self::line(['unitPrice' => 0])]]
        );

        self::assertSame([AnswerMapper::lineKey('A-1', 5) => 0.0], $prices);
    }

    public function testAMalformedAnswerChargesNothing(): void
    {
        $mapper = new AnswerMapper();

        self::assertSame([], $mapper->chargeablePrices(null));
        self::assertSame([], $mapper->chargeablePrices(['buyerMatched' => true, 'items' => 'x']));
        self::assertSame([], $mapper->chargeablePrices(['buyerMatched' => true, 'items' => ['x']]));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function verdicts(): array
    {
        return [
            'priced in the cart currency' => [
                ['status' => 'priced', 'currency' => 'usd', 'unitPrice' => 1], AnswerMapper::CURRENCY_MATCH,
            ],
            'priced in another currency (older server)' => [
                ['status' => 'priced', 'currency' => 'EUR', 'unitPrice' => 1], AnswerMapper::CURRENCY_MISMATCH,
            ],
            'withheld for another currency' => [
                [
                    'status' => 'unpriced',
                    'reason' => 'currency_mismatch',
                    'currency' => 'EUR',
                    'presentmentCurrency' => 'USD'
                ],
                AnswerMapper::CURRENCY_MISMATCH,
            ],
            'withheld but naming the cart currency' => [
                ['status' => 'unpriced', 'reason' => 'currency_mismatch', 'currency' => 'USD'],
                AnswerMapper::CURRENCY_UNKNOWN,
            ],
            'plain unpriced' => [['status' => 'unpriced'], AnswerMapper::CURRENCY_UNKNOWN],
            'unlinked' => [['status' => 'unlinked', 'reason' => 'customer_not_linked'], AnswerMapper::CURRENCY_UNKNOWN],
            'priced without a currency' => [['status' => 'priced', 'unitPrice' => 1], AnswerMapper::CURRENCY_UNKNOWN],
            'not an object' => [null, AnswerMapper::CURRENCY_UNKNOWN],
        ];
    }

    /**
     * @dataProvider verdicts
     * @param mixed $answer
     */
    public function testTheCurrencyVerdict($answer, string $expected): void
    {
        self::assertSame($expected, (new AnswerMapper())->currencyVerdict($answer, 'USD'));
    }

    public function testOrderTotalLimitsInTheCartCurrencyOrNoCurrencyApply(): void
    {
        $answer = [
            'status' => 'limited',
            'accountSpecific' => true,
            'limits' => [
                ['limitType' => 'order_total', 'min' => 500, 'max' => null, 'currency' => 'USD'],
                ['limitType' => 'order_total', 'min' => null, 'max' => 9000.5, 'currency' => null],
                ['limitType' => 'order_total', 'min' => 400, 'max' => null, 'currency' => 'EUR'],
                ['limitType' => 'order_total', 'min' => null, 'max' => null, 'currency' => null],
                ['limitType' => 'product_qty', 'min' => 5, 'max' => 10, 'currency' => null],
            ],
        ];

        self::assertSame(
            [['min' => 500.0, 'max' => null], ['min' => null, 'max' => 9000.5]],
            (new AnswerMapper())->orderTotalLimits($answer, 'usd')
        );
    }

    public function testOrderTotalLimitsNeedALimitedAnswer(): void
    {
        $mapper = new AnswerMapper();

        self::assertSame([], $mapper->orderTotalLimits(['status' => 'none'], 'USD'));
        self::assertSame([], $mapper->orderTotalLimits(null, 'USD'));
    }
}
