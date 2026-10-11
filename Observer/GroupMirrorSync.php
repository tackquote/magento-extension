<?php
/**
 * Runs the customer-group mirror for the signed-in customer: at once on sign-in
 * (`customer_login`), and otherwise at most every 5 minutes on a storefront request
 * (`controller_action_predispatch`), like WooCommerce's `wp_login` reset plus throttled
 * `template_redirect` check (class-tack-role-mirror.php `init()`).
 *
 * `customer_login` is dispatched by Magento\Customer\Model\Session::setCustomerAsLoggedIn() and
 * setCustomerDataAsLoggedIn() with the customer model (Customer/Model/Session.php:458, 479).
 * `controller_action_predispatch` runs before the action, so before
 * Customer\Model\App\Action\ContextPlugin puts the customer group into the page-cache context
 * (ContextPlugin.php:47-58): a changed group is in the same request's cache key.
 *
 * When the mirror moves the customer, the session is told too (Session::setCustomerGroupId(),
 * Session.php:374-378, and the session's customer model), so this request already prices and
 * caches as the new group.
 *
 * Declared in etc/frontend/events.xml only. Cheap when the mirror is off: the model reads the
 * switch before anything else.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Groups\CustomerGroupMirror;

class GroupMirrorSync implements ObserverInterface
{
    /**
     * @var CustomerGroupMirror
     */
    private $mirror;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param CustomerGroupMirror $mirror
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        CustomerGroupMirror $mirror,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager
    ) {
        $this->mirror = $mirror;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    /**
     * Run the customer-group mirror for the signed-in customer.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $isLogin = $event->getName() === 'customer_login';

        if ($isLogin) {
            $customer = $event->getData('customer');
            $customerId = is_object($customer) ? (int) $customer->getId() : 0;
        } else {
            if (!$this->customerSession->isLoggedIn()) {
                return;
            }
            $customerId = (int) $this->customerSession->getCustomerId();
        }
        if ($customerId < 1) {
            return;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return;
        }

        $outcome = $this->mirror->sync($customerId, $storeId, $isLogin);
        if ($outcome['groupId'] === null) {
            return;
        }

        $this->customerSession->setCustomerGroupId($outcome['groupId']);
        $sessionCustomer = $this->customerSession->getCustomer();
        if ($sessionCustomer && (int) $sessionCustomer->getId() === $customerId) {
            $sessionCustomer->setGroupId($outcome['groupId']);
        }
    }
}
