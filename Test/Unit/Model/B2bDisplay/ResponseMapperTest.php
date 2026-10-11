<?php
/**
 * Reading TackQuote's storefront answer unions (storefront-results.ts), failing closed.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\B2bDisplay;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\B2bDisplay\ResponseMapper;

/**
 * @covers \TackQuote\Quotes\Model\B2bDisplay\ResponseMapper
 */
class ResponseMapperTest extends TestCase
{
    /** @var ResponseMapper */
    private $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ResponseMapper();
    }

    // ------------------------------------------------------------ account price

    public function testAnAccountSpecificPriceInThePageCurrencyIsShown(): void
    {
        self::assertSame(
            ['unitPrice' => 12.5, 'currency' => 'USD'],
            $this->mapper->accountPrice(
                ['status' => 'priced', 'unitPrice' => 12.5, 'currency' => 'usd', 'quantity' => 1,
                    'accountSpecific' => true, 'checkoutApplied' => false],
                'USD'
            )
        );
    }

    public function testACataloguePriceIsNotPresentedAsTheAccountPrice(): void
    {
        self::assertNull($this->mapper->accountPrice(
            ['status' => 'priced', 'unitPrice' => 12.5, 'currency' => 'USD', 'accountSpecific' => false],
            'USD'
        ));
        self::assertNull($this->mapper->accountPrice(
            ['status' => 'priced', 'unitPrice' => 12.5, 'currency' => 'USD'],
            'USD'
        ), 'a missing accountSpecific is not true');
    }

    public function testAPriceInAnotherCurrencyIsWithheldNotConverted(): void
    {
        self::assertNull($this->mapper->accountPrice(
            ['status' => 'priced', 'unitPrice' => 12.5, 'currency' => 'EUR', 'accountSpecific' => true],
            'USD'
        ));
        self::assertNull($this->mapper->accountPrice(
            ['status' => 'priced', 'unitPrice' => 12.5, 'accountSpecific' => true],
            'USD'
        ), 'no currency, no price');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unpricedAnswers(): array
    {
        return [
            'anonymous' => [['status' => 'anonymous']],
            'unlinked' => [['status' => 'unlinked', 'reason' => 'customer_not_linked']],
            'unpriced' => [['status' => 'unpriced']],
            'currency mismatch' => [['status' => 'unpriced', 'reason' => 'currency_mismatch',
                'currency' => 'USD', 'presentmentCurrency' => 'USD']],
            'a future status' => [['status' => 'priced_v2', 'unitPrice' => 1, 'currency' => 'USD', 'accountSpecific' => true]],
            'null price' => [['status' => 'priced', 'unitPrice' => null, 'currency' => 'USD', 'accountSpecific' => true]],
            'string price' => [['status' => 'priced', 'unitPrice' => 'free', 'currency' => 'USD', 'accountSpecific' => true]],
            'boolean price' => [['status' => 'priced', 'unitPrice' => true, 'currency' => 'USD', 'accountSpecific' => true]],
            'negative price' => [['status' => 'priced', 'unitPrice' => -1, 'currency' => 'USD', 'accountSpecific' => true]],
            'not an object' => ['priced'],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider unpricedAnswers
     * @param mixed $answer
     */
    public function testEveryOtherAnswerShowsNoPrice($answer): void
    {
        self::assertNull($this->mapper->accountPrice($answer, 'USD'));
    }

    public function testZeroIsARealPriceNotAMissingOne(): void
    {
        self::assertSame(
            ['unitPrice' => 0.0, 'currency' => 'USD'],
            $this->mapper->accountPrice(
                ['status' => 'priced', 'unitPrice' => 0, 'currency' => 'USD', 'accountSpecific' => true],
                'USD'
            )
        );
    }

    // ------------------------------------------------------------ quantity breaks

    public function testALadderIsSortedAndRungsAtTheSamePriceAreFolded(): void
    {
        $result = $this->mapper->quantityBreaks(
            [
                'status' => 'priced',
                'currency' => 'USD',
                'accountSpecific' => true,
                'rows' => [
                    ['minQty' => 50, 'unitPrice' => 8, 'accountSpecific' => true],
                    ['minQty' => 1, 'unitPrice' => 10, 'accountSpecific' => true],
                    ['minQty' => 10, 'unitPrice' => 10, 'accountSpecific' => true],
                    ['minQty' => 25, 'unitPrice' => 9, 'accountSpecific' => true],
                ],
            ],
            'USD'
        );

        self::assertSame(
            [
                'currency' => 'USD',
                'accountSpecific' => true,
                'rows' => [
                    ['minQty' => 1, 'unitPrice' => 10.0],
                    ['minQty' => 25, 'unitPrice' => 9.0],
                    ['minQty' => 50, 'unitPrice' => 8.0],
                ],
            ],
            $result
        );
    }

    public function testALadderWithOneDistinctPriceIsNotAnyLadder(): void
    {
        self::assertNull($this->mapper->quantityBreaks(
            ['status' => 'priced', 'currency' => 'USD', 'accountSpecific' => true, 'rows' => [
                ['minQty' => 1, 'unitPrice' => 10],
                ['minQty' => 10, 'unitPrice' => 10],
            ]],
            'USD'
        ));
    }

    public function testMalformedRungsAreSkippedAndCanEmptyTheLadder(): void
    {
        self::assertNull($this->mapper->quantityBreaks(
            ['status' => 'priced', 'currency' => 'USD', 'rows' => [
                ['minQty' => 1, 'unitPrice' => 10],
                ['minQty' => 'ten', 'unitPrice' => 9],
                ['minQty' => 20, 'unitPrice' => null],
                'garbage',
            ]],
            'USD'
        ));
    }

    public function testALadderInAnotherCurrencyOrWithoutPricedStatusIsWithheld(): void
    {
        $rows = [['minQty' => 1, 'unitPrice' => 10], ['minQty' => 10, 'unitPrice' => 9]];

        self::assertNull($this->mapper->quantityBreaks(
            ['status' => 'priced', 'currency' => 'EUR', 'rows' => $rows],
            'USD'
        ));
        self::assertNull($this->mapper->quantityBreaks(['status' => 'unlinked', 'rows' => $rows], 'USD'));
        self::assertNotNull($this->mapper->quantityBreaks(
            ['status' => 'priced', 'currency' => 'USD', 'rows' => $rows],
            'USD'
        ));
    }

    public function testAPublicLadderIsNotMarkedAsTheAccounts(): void
    {
        $result = $this->mapper->quantityBreaks(
            ['status' => 'priced', 'currency' => 'USD', 'accountSpecific' => false, 'rows' => [
                ['minQty' => 1, 'unitPrice' => 10],
                ['minQty' => 10, 'unitPrice' => 9],
            ]],
            'USD'
        );

        self::assertFalse($result['accountSpecific']);
    }

    // ------------------------------------------------------------ order limits

    public function testTheFirstQuantityRuleWithABoundIsUsed(): void
    {
        self::assertSame(
            ['min' => 5, 'max' => 100, 'accountSpecific' => true],
            $this->mapper->orderLimit([
                'status' => 'limited',
                'accountSpecific' => true,
                'limits' => [
                    ['limitType' => 'order_total', 'sku' => null, 'min' => 500, 'max' => null,
                        'currency' => 'USD', 'message' => null],
                    ['limitType' => 'per_product_qty', 'sku' => 'A', 'min' => null, 'max' => null,
                        'currency' => null, 'message' => null],
                    ['limitType' => 'product_qty', 'sku' => 'A', 'min' => 5, 'max' => 100,
                        'currency' => null, 'message' => null],
                ],
            ])
        );
    }

    public function testAMoneyLimitIsNeverReadAsAQuantity(): void
    {
        self::assertNull($this->mapper->orderLimit([
            'status' => 'limited',
            'limits' => [['limitType' => 'order_total', 'min' => 500, 'max' => null]],
        ]));
    }

    public function testTheLegacyFieldNamesThatNoServerSendsAreIgnored(): void
    {
        // WooCommerce up to 1.8.x read minQuantity/maxQuantity; the server sends min/max.
        self::assertNull($this->mapper->orderLimit([
            'status' => 'limited',
            'limits' => [['limitType' => 'product_qty', 'minQuantity' => 5, 'maxQuantity' => 10]],
        ]));
    }

    public function testNoneAndUnlinkedShowNoLimit(): void
    {
        self::assertNull($this->mapper->orderLimit(['status' => 'none']));
        self::assertNull($this->mapper->orderLimit(['status' => 'unlinked', 'reason' => 'customer_not_linked']));
        self::assertNull($this->mapper->orderLimit(['status' => 'limited', 'limits' => 'x']));
    }

    public function testAMinimumAloneOrAMaximumAloneIsKept(): void
    {
        self::assertSame(
            ['min' => 3, 'max' => null, 'accountSpecific' => false],
            $this->mapper->orderLimit(['status' => 'limited', 'limits' => [
                ['limitType' => 'product_qty', 'min' => 3, 'max' => null],
            ]])
        );
        self::assertSame(
            ['min' => null, 'max' => 9, 'accountSpecific' => false],
            $this->mapper->orderLimit(['status' => 'limited', 'limits' => [
                ['limitType' => 'product_qty', 'min' => 0, 'max' => 9],
            ]]),
            'a minimum of 0 constrains nothing'
        );
    }

    // ------------------------------------------------------------ buyer group

    public function testAGroupedBuyerShowsTheGroupName(): void
    {
        self::assertSame(
            ['name' => 'Tier 2'],
            $this->mapper->buyerGroup(['status' => 'grouped', 'name' => ' Tier 2 ', 'code' => 'T2', 'taxExempt' => false])
        );
    }

    public function testNoGroupOrNoNameShowsNoBadge(): void
    {
        self::assertNull($this->mapper->buyerGroup(['status' => 'none', 'taxExempt' => true]));
        self::assertNull($this->mapper->buyerGroup(['status' => 'anonymous']));
        self::assertNull($this->mapper->buyerGroup(['status' => 'grouped', 'name' => '   ']));
        self::assertNull($this->mapper->buyerGroup(['status' => 'grouped', 'name' => ['x']]));
    }
}
