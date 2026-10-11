<?php
/**
 * Payment and shipping methods by buyer group, and shipping discounts: WooCommerce's rules,
 * including "never empty the list".
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Groups\Audience;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\GroupRules;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;

/**
 * @covers \TackQuote\Quotes\Model\Groups\MethodRestrictions
 */
class MethodRestrictionsTest extends TestCase
{
    /** @var GroupRulesConfig&MockObject */
    private $config;

    /** @var Audience&MockObject */
    private $audience;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var array<string, string> */
    private $store = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->audience = $this->createMock(Audience::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(function ($id) {
            return $this->store[$id] ?? false;
        });
        $this->cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->store[$id] = $data;

            return true;
        });
        $this->cache->method('remove')->willReturnCallback(function ($id) {
            unset($this->store[$id]);

            return true;
        });
    }

    private function restrictions(): MethodRestrictions
    {
        return new MethodRestrictions($this->config, $this->audience, $this->cache, $this->logger);
    }

    /**
     * @return CartInterface&MockObject
     */
    private function cart(int $id = 10): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getStoreId')->willReturn(1);
        $cart->method('getId')->willReturn($id);

        return $cart;
    }

    private function rate(string $carrier, string $method, float $price): Method
    {
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('round')->willReturnCallback(static function ($p) {
            return round((float) $p, 2);
        });
        $rate = new Method($currency);
        $rate->setData(['carrier' => $carrier, 'method' => $method, 'price' => $price]);

        return $rate;
    }

    private function buyer(BuyerGroupAnswer $answer): void
    {
        $this->audience->method('forQuote')->willReturn($answer);
    }

    private function paymentRules(array $map, bool $strict = false): void
    {
        $this->config->method('isRestrictionsActive')->willReturn(true);
        $this->config->method('getPaymentMap')->willReturn($map);
        $this->config->method('restrictWhenUnknown')->willReturn($strict);
    }

    public function testNoRulesNoLookup(): void
    {
        $this->config->method('isRestrictionsActive')->willReturn(false);
        $this->audience->expects(self::never())->method('forQuote');

        self::assertTrue($this->restrictions()->isPaymentPermitted('checkmo', $this->cart()));
    }

    public function testAnUnrestrictedMethodNeedsNoLookup(): void
    {
        $this->paymentRules(['purchaseorder' => ['TRADE']]);
        $this->audience->expects(self::never())->method('forQuote');

        self::assertTrue($this->restrictions()->isPaymentPermitted('checkmo', $this->cart()));
    }

    public function testARestrictedMethodIsForItsGroupsOnly(): void
    {
        $this->paymentRules(['purchaseorder' => ['TRADE']]);
        $this->buyer(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));

        self::assertFalse($this->restrictions()->isPaymentPermitted('purchaseorder', $this->cart()));
    }

    public function testTheGroupItNamesMayUseIt(): void
    {
        $this->paymentRules(['purchaseorder' => ['TRADE']]);
        $this->buyer(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'trade'));

        self::assertTrue($this->restrictions()->isPaymentPermitted('purchaseorder', $this->cart()));
    }

    public function testASuspendedCartIsNotRestrictedUntilResumed(): void
    {
        $this->paymentRules(['purchaseorder' => ['TRADE']]);
        $this->buyer(new BuyerGroupAnswer(BuyerGroupAnswer::NONE));
        $restrictions = $this->restrictions();
        $cart = $this->cart(10);

        $restrictions->suspendPayment($cart);
        self::assertTrue($restrictions->isPaymentPermitted('purchaseorder', $cart));
        self::assertFalse($restrictions->isPaymentPermitted('purchaseorder', $this->cart(11)), 'per cart');

        $restrictions->resumePayment($cart);
        self::assertFalse($restrictions->isPaymentPermitted('purchaseorder', $cart));
    }

    public function testAShippingRestrictionRemovesTheRateButKeepsErrors(): void
    {
        $error = new Error();
        $rates = [$this->rate('flatrate', 'flatrate', 5.0), $this->rate('freeshipping', 'freeshipping', 0.0), $error];

        $out = $this->restrictions()->filterRates(
            $rates,
            ['freeshipping' => ['GOLD']],
            new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'SILVER'),
            false
        );

        self::assertSame([$rates[0], $error], $out);
    }

    public function testShippingIsNeverEmptied(): void
    {
        $rates = [$this->rate('freeshipping', 'freeshipping', 0.0)];
        $this->logger->expects(self::once())->method('warning');

        $out = $this->restrictions()->filterRates(
            $rates,
            ['freeshipping' => ['GOLD']],
            new BuyerGroupAnswer(BuyerGroupAnswer::NONE),
            false
        );

        self::assertSame($rates, $out);
    }

    public function testAPercentDiscountRepricesTheCoveredRatesOnly(): void
    {
        $flat = $this->rate('flatrate', 'flatrate', 10.0);
        $table = $this->rate('tablerate', 'bestway', 20.0);

        $this->restrictions()->discountRates(
            [$flat, $table],
            ['GOLD' => ['mode' => GroupRules::DISCOUNT_PERCENT, 'percent' => 15.0, 'methods' => ['flatrate']]],
            new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD')
        );

        self::assertSame(8.5, $flat->getPrice());
        self::assertSame(20.0, $table->getPrice());
    }

    public function testADiscountIsAGrantOnlyAGroupedBuyerGets(): void
    {
        $flat = $this->rate('flatrate', 'flatrate', 10.0);
        $rules = ['@GUESTS' => ['mode' => GroupRules::DISCOUNT_FREE, 'percent' => 100.0, 'methods' => []]];

        $this->restrictions()->discountRates([$flat], $rules, new BuyerGroupAnswer(BuyerGroupAnswer::NONE));
        $this->restrictions()->discountRates([$flat], $rules, new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE));

        self::assertSame(10.0, $flat->getPrice());
    }

    public function testFreeOnlyKeepsTheFreeRatesWhenThereIsOne(): void
    {
        $paid = $this->rate('flatrate', 'flatrate', 10.0);
        $free = $this->rate('freeshipping', 'freeshipping', 0.0);
        $rules = ['TIER3' => ['mode' => GroupRules::DISCOUNT_FREE_ONLY, 'percent' => 100.0, 'methods' => []]];
        $tier3 = new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'TIER3');

        self::assertSame([$free], $this->restrictions()->discountRates([$paid, $free], $rules, $tier3));
        self::assertSame([$paid], $this->restrictions()->discountRates([$paid], $rules, $tier3), 'never empty');
    }

    public function testApplyToRatesRestrictsThenDiscounts(): void
    {
        $this->config->method('isRestrictionsActive')->willReturn(true);
        $this->config->method('getShippingMap')->willReturn(['tablerate' => ['TRADE']]);
        $this->config->method('isDiscountsActive')->willReturn(true);
        $this->config->method('getDiscountMap')->willReturn([
            'GOLD' => ['mode' => GroupRules::DISCOUNT_FREE, 'percent' => 100.0, 'methods' => []],
        ]);
        $this->buyer(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));
        $flat = $this->rate('flatrate', 'flatrate', 10.0);
        $table = $this->rate('tablerate', 'bestway', 20.0);

        $out = $this->restrictions()->applyToRates([$flat, $table], $this->cart());

        self::assertSame([$flat], $out);
        self::assertSame(0.0, $flat->getPrice());
    }

    public function testApplyToRatesLeavesAnExemptCallerAlone(): void
    {
        $this->config->method('isRestrictionsActive')->willReturn(true);
        $this->config->method('getShippingMap')->willReturn(['flatrate' => ['TRADE']]);
        $this->buyer(new BuyerGroupAnswer(BuyerGroupAnswer::EXEMPT));

        self::assertNull($this->restrictions()->applyToRates([$this->rate('flatrate', 'flatrate', 5.0)], $this->cart()));
    }
}
