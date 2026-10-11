<?php
/**
 * The shipping plugin hands every carrier's rates for a cart to the rules and puts back what
 * they keep, and leaves a request that is not a cart's alone.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Shipping;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Item;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Shipping;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;
use TackQuote\Quotes\Plugin\Shipping\GroupShippingRates;

/**
 * @covers \TackQuote\Quotes\Plugin\Shipping\GroupShippingRates
 */
class GroupShippingRatesTest extends TestCase
{
    private function rate(string $carrier): Method
    {
        $rate = new Method($this->createMock(PriceCurrencyInterface::class));
        $rate->setData(['carrier' => $carrier, 'method' => $carrier, 'price' => 5.0]);

        return $rate;
    }

    public function testTheRatesTheRulesKeepReplaceTheResult(): void
    {
        $result = new Result($this->createMock(StoreManagerInterface::class));
        $flat = $this->rate('flatrate');
        $free = $this->rate('freeshipping');
        $result->append($flat);
        $result->append($free);
        $shipping = $this->createMock(Shipping::class);
        $shipping->method('getResult')->willReturn($result);

        $quote = $this->createMock(Quote::class);
        $item = $this->createMock(Item::class);
        $item->method('getQuote')->willReturn($quote);
        $request = new RateRequest();
        $request->setAllItems([$item]);

        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->expects(self::once())->method('applyToRates')->with([$flat, $free], $quote)->willReturn([$flat]);

        $out = (new GroupShippingRates($restrictions))->afterCollectRates($shipping, $shipping, $request);

        self::assertSame($shipping, $out);
        self::assertSame([$flat], $result->getAllRates());
    }

    public function testNoRuleLeavesTheResultUntouched(): void
    {
        $result = new Result($this->createMock(StoreManagerInterface::class));
        $flat = $this->rate('flatrate');
        $result->append($flat);
        $shipping = $this->createMock(Shipping::class);
        $shipping->method('getResult')->willReturn($result);
        $item = $this->createMock(Item::class);
        $item->method('getQuote')->willReturn($this->createMock(Quote::class));
        $request = new RateRequest();
        $request->setAllItems([$item]);
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->method('applyToRates')->willReturn(null);

        (new GroupShippingRates($restrictions))->afterCollectRates($shipping, $shipping, $request);

        self::assertSame([$flat], $result->getAllRates());
    }

    public function testARequestWithoutCartItemsIsNotACartsAndIsLeftAlone(): void
    {
        $shipping = $this->createMock(Shipping::class);
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->expects(self::never())->method('applyToRates');

        (new GroupShippingRates($restrictions))->afterCollectRates($shipping, $shipping, new RateRequest());
    }
}
