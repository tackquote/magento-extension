<?php
/**
 * The payment rules never leave a cart with no way to pay.
 *
 * WooCommerce's guard (class-tack-group-restrictions.php `filter_gateways()`): "NEVER return an
 * empty gateway list ... If the rules would remove everything, the rules are wrong; keep the
 * original set and say so in the log."
 *
 * Here the rules act per method (Observer\PaymentMethodGroupRestriction), so the whole list is
 * only visible at Magento\Payment\Model\MethodList::getAvailableMethods(). When that list comes
 * back EMPTY on a store with payment rules, this plugin suspends the rules for that cart
 * (MethodRestrictions::suspendPayment(), logged) and asks again. Only when the second answer
 * has methods were the rules the cause; the suspension then also lets the later "set payment
 * method" request (Quote\Payment::importData(), which asks isAvailable() again) accept the
 * method checkout offered. A cart that has no method even without the rules is Magento's own
 * situation and is returned as it was.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Payment;

use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\Data\CartInterface;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;

class MethodListRestriction
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
     * @param MethodList $subject
     * @param callable $proceed
     * @param CartInterface|null $quote
     * @return array
     */
    public function aroundGetAvailableMethods(MethodList $subject, callable $proceed, ?CartInterface $quote = null)
    {
        $methods = $proceed($quote);
        if ($methods !== [] || $quote === null
            || !$this->restrictions->hasPaymentRules((int) $quote->getStoreId())
            || $this->restrictions->isPaymentSuspended($quote)
        ) {
            return $methods;
        }

        $this->restrictions->suspendPayment($quote);
        $unrestricted = $proceed($quote);
        if ($unrestricted === []) {
            // Not the rules: Magento offers this cart nothing either way.
            $this->restrictions->resumePayment($quote);

            return $methods;
        }
        $this->restrictions->logPaymentSuspended();

        return $unrestricted;
    }
}
