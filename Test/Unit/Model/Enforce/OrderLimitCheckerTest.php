<?php
/**
 * Minimum and maximum per SKU (each boundary), the order total at submit (each boundary), the
 * notice's own wording, and nothing refused without an answer or without the switch.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;
use TackQuote\Quotes\Model\B2bDisplay\ResponseMapper;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;
use TackQuote\Quotes\Model\Enforce\Eligibility;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;
use TackQuote\Quotes\Model\Enforce\OrderLimitProvider;
use TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\OrderLimitChecker
 */
class OrderLimitCheckerTest extends TestCase
{
    private const BUYER = ['storeId' => 1, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD'];

    /** @var array<string, array|null> SKU => order-limits answer */
    private $answers = [];

    /** @var bool */
    private $enabled = true;

    /** @var array|null */
    private $buyer = self::BUYER;

    private function checker(): OrderLimitChecker
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnforceOrderLimitsEnabled')->willReturnCallback(function (): bool {
            return $this->enabled;
        });
        $eligibility = $this->createMock(Eligibility::class);
        $eligibility->method('buyer')->willReturnCallback(function () {
            return $this->buyer;
        });
        $provider = $this->createMock(OrderLimitProvider::class);
        $provider->method('forSku')->willReturnCallback(function (array $buyer, string $sku) {
            return $this->answers[$sku] ?? null;
        });
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static function ($amount, $includeContainer, $precision, $scope, $currency) {
                return $currency . ' ' . number_format((float) $amount, 2);
            }
        );
        $presenter = new Presenter(
            $this->createMock(CatalogHelper::class),
            $priceCurrency,
            $this->createMock(PriceEnforcementSwitch::class)
        );

        return new OrderLimitChecker(
            $config,
            $eligibility,
            $provider,
            new ResponseMapper(),
            new AnswerMapper(),
            $presenter,
            $priceCurrency
        );
    }

    private static function limited(?int $min, ?int $max, array $more = []): array
    {
        return [
            'status' => 'limited',
            'accountSpecific' => true,
            'limits' => array_merge(
                [[
                    'limitType' => 'product_qty',
                    'sku' => 'A',
                    'min' => $min,
                    'max' => $max,
                    'currency' => null,
                    'message' => 'seller text'
                ]],
                $more
            ),
        ];
    }

    private function line(int $id, string $sku, float $qty, string $name = 'Widget'): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $item->method('getId')->willReturn($id);
        $item->setData(['sku' => $sku, 'qty' => $qty, 'name' => $name]);

        return $item;
    }

    /**
     * @param Item[] $items
     */
    private function quote(array $items, float $grandTotal = 0.0): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'getStoreId'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getStoreId')->willReturn(1);
        $quote->setData('grand_total', $grandTotal);

        return $quote;
    }

    private function refusal(callable $call): ?string
    {
        try {
            $call();
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function minBoundary(): array
    {
        return ['one below' => [4.0, true], 'at' => [5.0, false], 'above' => [6.0, false]];
    }

    /**
     * @dataProvider minBoundary
     */
    public function testTheMinimumAtAdd(float $qty, bool $refused): void
    {
        $this->answers['A'] = self::limited(5, null);

        $message = $this->refusal(function () use ($qty): void {
            $this->checker()->assertAdd($this->quote([]), 'A', $qty, 'Widget');
        });

        self::assertSame($refused, $message !== null);
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function maxBoundary(): array
    {
        return ['below' => [49.0, false], 'at' => [50.0, false], 'one above' => [51.0, true]];
    }

    /**
     * @dataProvider maxBoundary
     */
    public function testTheMaximumAtAdd(float $qty, bool $refused): void
    {
        $this->answers['A'] = self::limited(null, 50);

        $message = $this->refusal(function () use ($qty): void {
            $this->checker()->assertAdd($this->quote([]), 'A', $qty, 'Widget');
        });

        self::assertSame($refused, $message !== null);
    }

    public function testTheRefusalRepeatsTheNoticesSentence(): void
    {
        $this->answers['A'] = self::limited(5, 50);

        $message = $this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([$this->line(1, 'A', 40)]), 'A', 20, 'Widget');
        });

        // The add was refused, so the cart still holds 40: it never says "You have 60".
        self::assertSame(
            'Widget: Order between 5 and 50 units of this product. You have 40 in your cart and tried to add 20.',
            $message
        );
        self::assertStringNotContainsString("\u{2014}", (string) $message);
    }

    /**
     * Devstore Q/A finding #9. Mutations (applied, red, restored):
     *   M1  the add sentence says "You have <total> in your cart" -> this test
     *   M2  the update sentence says "You have" -> testARefusedChangeSaysWhatWasAskedFor
     *   M3  the submit sentence says "You tried to add" -> testTheOrderSubmitSaysWhatIsInTheCart
     */
    public function testARefusedAddToAnEmptyCartSaysWhatWasAttempted(): void
    {
        $this->answers['A'] = self::limited(2, 10);

        $message = $this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([]), 'A', 1, 'Widget');
        });

        self::assertSame('Widget: Order between 2 and 10 units of this product. You tried to add 1.', $message);
    }

    public function testARefusedChangeSaysWhatWasAskedFor(): void
    {
        $this->answers['A'] = self::limited(5, 50);
        $quote = $this->quote([$this->line(1, 'A', 10)]);

        $message = $this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, [1 => 51]);
        });

        self::assertSame('Widget: Order between 5 and 50 units of this product. You asked for 51.', $message);
    }

    public function testTheOrderSubmitSaysWhatIsInTheCart(): void
    {
        $this->answers['A'] = self::limited(5, 50);

        $message = $this->refusal(function (): void {
            $this->checker()->assertCart($this->quote([$this->line(1, 'A', 60)]));
        });

        self::assertSame('Widget: Order between 5 and 50 units of this product. You have 60 in your cart.', $message);
    }

    public function testQuantitiesAreSummedPerSkuAcrossLines(): void
    {
        // 10 + 20 meets a minimum of 25 that neither line meets alone.
        $this->answers['A'] = self::limited(25, null);
        $quote = $this->quote([$this->line(1, 'A', 10), $this->line(2, 'A', 20)]);

        self::assertNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertCart($quote);
        }));
    }

    public function testAnUpdateChecksTheNewQuantity(): void
    {
        $this->answers['A'] = self::limited(5, 50);
        $quote = $this->quote([$this->line(1, 'A', 10), $this->line(2, 'B', 1)]);

        self::assertNotNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, ['1' => 51]);
        }));
        self::assertNotNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, [1 => 4]);
        }));
        self::assertNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, [1 => 50]);
        }));
    }

    public function testRemovingALineIsNeverRefusedByItsMinimum(): void
    {
        $this->answers['A'] = self::limited(5, null);
        $quote = $this->quote([$this->line(1, 'A', 10)]);

        self::assertNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, [1 => 0]);
        }));
    }

    public function testAChangeChecksOnlyTheSkusItTouches(): void
    {
        // B sits below its minimum; changing A must not be refused for it.
        $this->answers['A'] = self::limited(1, 100);
        $this->answers['B'] = [
            'status' => 'limited',
            'limits' => [['limitType' => 'product_qty', 'min' => 10, 'max' => null]]
        ];
        $quote = $this->quote([$this->line(1, 'A', 2), $this->line(2, 'B', 1)]);

        self::assertNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertItemQuantities($quote, [1 => 3]);
            $this->checker()->assertAdd($quote, 'A', 1, 'Widget');
        }));
        self::assertNotNull($this->refusal(function () use ($quote): void {
            $this->checker()->assertCart($quote);
        }), 'the order submit checks every line');
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function totalMinBoundary(): array
    {
        return ['a cent below' => [499.99, true], 'at' => [500.0, false]];
    }

    /**
     * @dataProvider totalMinBoundary
     */
    public function testTheOrderTotalMinimumAtSubmit(float $total, bool $refused): void
    {
        $this->answers['A'] = self::limited(null, null, [
            ['limitType' => 'order_total', 'min' => 500, 'max' => null, 'currency' => 'USD'],
        ]);

        $message = $this->refusal(function () use ($total): void {
            $this->checker()->assertCart($this->quote([$this->line(1, 'A', 1)], $total));
        });

        self::assertSame($refused, $message !== null);
        if ($refused) {
            self::assertSame('The order total must be at least USD 500.00.', $message);
        }
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function totalMaxBoundary(): array
    {
        return ['at' => [1000.0, false], 'a cent above' => [1000.01, true]];
    }

    /**
     * @dataProvider totalMaxBoundary
     */
    public function testTheOrderTotalMaximumAtSubmit(float $total, bool $refused): void
    {
        $this->answers['A'] = ['status' => 'limited', 'limits' => [
            ['limitType' => 'order_total', 'min' => null, 'max' => 1000, 'currency' => null],
        ]];

        $message = $this->refusal(function () use ($total): void {
            $this->checker()->assertCart($this->quote([$this->line(1, 'A', 1)], $total));
        });

        self::assertSame($refused, $message !== null);
    }

    public function testAnOrderTotalLimitInAnotherCurrencyDoesNotApply(): void
    {
        $this->answers['A'] = ['status' => 'limited', 'limits' => [
            ['limitType' => 'order_total', 'min' => 500, 'max' => null, 'currency' => 'EUR'],
        ]];

        self::assertNull($this->refusal(function (): void {
            $this->checker()->assertCart($this->quote([$this->line(1, 'A', 1)], 10.0));
        }));
    }

    public function testAnOrderTotalLimitIsNotCheckedWhenAddingToTheCart(): void
    {
        $this->answers['A'] = ['status' => 'limited', 'limits' => [
            ['limitType' => 'order_total', 'min' => 500, 'max' => null, 'currency' => null],
        ]];

        self::assertNull($this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([], 10.0), 'A', 1, 'Widget');
        }));
    }

    public function testNoAnswerRefusesNothing(): void
    {
        self::assertNull($this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([]), 'A', 1000, 'Widget');
            $this->checker()->assertCart($this->quote([$this->line(1, 'A', 1000)]));
        }));
    }

    public function testTheSwitchOffOrAnIneligibleCallerRefusesNothing(): void
    {
        $this->answers['A'] = self::limited(5, null);

        $this->enabled = false;
        self::assertNull($this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([]), 'A', 1, 'Widget');
        }));

        $this->enabled = true;
        $this->buyer = null;
        self::assertNull($this->refusal(function (): void {
            $this->checker()->assertAdd($this->quote([]), 'A', 1, 'Widget');
        }));
    }
}
