<?php
/**
 * Payment methods restricted by TackQuote buyer group ("Net 30 is for approved trade accounts
 * only", WooCommerce class-tack-group-restrictions.php).
 *
 * WHY AN OBSERVER ON `payment_method_is_active`. Both payment method bases dispatch it from
 * isAvailable() with a mutable `result` (`is_available`), the method and the cart:
 * Magento\Payment\Model\Method\AbstractMethod::isAvailable() (Payment/Model/Method/
 * AbstractMethod.php:783-796) and the gateway Magento\Payment\Model\Method\Adapter::isAvailable()
 * (Payment/Model/Method/Adapter.php:305-315), the event's documented purpose ("for future use in
 * observers"). isAvailable() is asked in BOTH places that matter:
 *   - listing: Magento\Payment\Model\MethodList::getAvailableMethods() keeps a method only when
 *     `$methodInstance->isAvailable($quote)` (Payment/Model/MethodList.php:76-83), which is the
 *     list checkout shows;
 *   - choosing: Magento\Quote\Model\Quote\Payment::importData() refuses "The requested Payment
 *     Method is not available." unless `$method->isAvailable($quote)` (Quote/Model/Quote/
 *     Payment.php:188-195), which every "set payment method" path (checkout, REST, GraphQL)
 *     goes through.
 * So a hidden method is neither offered nor accepted. A plugin on MethodList alone would only
 * hide it from the list.
 *
 * Declared in etc/frontend, etc/webapi_rest and etc/graphql events.xml: the admin's own orders
 * are never restricted (and Audience treats admin and integration API callers as exempt).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;

class PaymentMethodGroupRestriction implements ObserverInterface
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
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $result = $event->getData('result');
        $method = $event->getData('method_instance');
        $quote = $event->getData('quote');

        // No cart (a store-level availability question), or already unavailable: nothing to do.
        if (!$result instanceof DataObject || !$result->getData('is_available')
            || !$method instanceof MethodInterface || !$quote instanceof CartInterface
        ) {
            return;
        }

        if (!$this->restrictions->isPaymentPermitted((string) $method->getCode(), $quote)) {
            $result->setData('is_available', false);
        }
    }
}
