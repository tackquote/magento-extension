<?php
/**
 * Shipping methods by TackQuote buyer group, and shipping discounts per group.
 *
 * WHY THIS POINT. A cart's shipping rates are collected in one place:
 * Magento\Quote\Model\Quote\Address::requestShippingRates() calls
 * `$this->_rateCollector->create()->collectRates($request)->getResult()` and turns
 * `getAllRates()` into the address's rates (Quote/Model/Quote/Address.php:1090-1100, Magento
 * 2.4-develop); the collector is Magento\Shipping\Model\Shipping, whose collectRates() asks
 * every active carrier and appends to one Rate\Result (Shipping/Model/Shipping.php:204-260).
 * An after-plugin on collectRates() therefore sees every carrier's rates for the cart, and
 * what it removes never becomes an address rate. Order placement accepts only a shipping
 * method that is among the address's rates (Quote/Model/ValidationRules/
 * ShippingMethodValidationRule.php), so a removed method cannot be used through the API
 * either.
 *
 * Rate\Result keeps its rates in a list it exposes as getAllRates() and lets a caller reset()
 * and append() (Shipping/Model/Rate/Result.php:50-106); the asynchronous CarrierResult drains
 * its pending carrier results into that list on getAllRates() (Rate/CarrierResult.php:36-55),
 * so reading them first and then resetting is safe for both.
 *
 * Rate keys are Magento's: a rule line names the carrier (`flatrate`) or the full rate code
 * (`flatrate_flatrate`), the full code first (GroupRules::shippingRuleKey()).
 *
 * DISCOUNTS change the rate's price (RateResult\Method::setPrice()) before Magento imports it
 * into the address. Shipping tax is calculated later from the address's shipping amount by the
 * tax collectors, so it follows the discounted price on its own; WooCommerce had to rescale
 * the rate's taxes itself because there the taxes ride on the rate.
 *
 * The cart comes from the request's items (each quote item knows its cart). A request with no
 * item is not a cart's (an admin shipment's, a carrier test), and is left alone.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Shipping;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Shipping\Model\Shipping;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;

class GroupShippingRates
{
    /**
     * @var MethodRestrictions
     */
    private $restrictions;

    /**
     * @param MethodRestrictions $restrictions
     */
    public function __construct(MethodRestrictions $restrictions)
    {
        $this->restrictions = $restrictions;
    }

    /**
     * @param Shipping $subject
     * @param Shipping $result
     * @param RateRequest $request
     * @return Shipping
     */
    public function afterCollectRates(Shipping $subject, $result, RateRequest $request)
    {
        $quote = $this->quoteOf($request);
        if ($quote === null) {
            return $result;
        }

        $rateResult = $subject->getResult();
        $kept = $this->restrictions->applyToRates($rateResult->getAllRates(), $quote);
        if ($kept === null) {
            return $result;
        }

        $rateResult->reset();
        foreach ($kept as $rate) {
            $rateResult->append($rate);
        }

        return $result;
    }

    /**
     * @param RateRequest $request
     * @return CartInterface|null
     */
    private function quoteOf(RateRequest $request): ?CartInterface
    {
        $items = $request->getAllItems();
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if ($item instanceof AbstractItem) {
                $quote = $item->getQuote();
                if ($quote instanceof CartInterface) {
                    return $quote;
                }
            }
        }

        return null;
    }
}
