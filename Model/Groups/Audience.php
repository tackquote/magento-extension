<?php
/**
 * Who a buyer-group rule is deciding for: the visitor of this request, or the customer a
 * cart belongs to.
 *
 * WHO IS NEVER FILTERED. The same per-area Model\QuoteOnly\VisitorContextInterface that
 * quote-only mode uses decides it: in the admin, cron and CLI the global binding is
 * NoVisitorContext (always exempt), and in REST, SOAP and GraphQL an admin or integration
 * token is exempt (ApiVisitorContext::isExempt()). That keeps the merchant's admin orders and
 * TackQuote's own quote conversion (guest-cart REST calls signed as an integration) out of
 * every rule. WooCommerce's equivalent is `Tack_Catalog_Visibility::shopper_context()`.
 *
 * WHO IS NOT ASKED ABOUT. A guest is GUEST without any lookup (WooCommerce: "Signed-out
 * visitors never need a lookup: they are guests by definition, so an outage cannot un-hide a
 * guest rule"). So is a signed-in customer outside the merchant's "Only for these customer
 * groups" selection: TackQuote finds the buyer by email address, which Magento does not
 * confirm unless account confirmation is on, so the merchant can keep a new account that typed
 * someone else's address from inheriting that buyer's group (the B2B display offers the same
 * filter, README "Who sees what").
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;

class Audience
{
    /**
     * @var VisitorContextInterface
     */
    private $visitor;

    /**
     * @var BuyerGroupResolver
     */
    private $resolver;

    /**
     * @var GroupRulesConfig
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var BuyerGroupAnswer|null
     */
    private $current;

    /**
     * @param VisitorContextInterface $visitor
     * @param BuyerGroupResolver $resolver
     * @param GroupRulesConfig $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        VisitorContextInterface $visitor,
        BuyerGroupResolver $resolver,
        GroupRulesConfig $config,
        StoreManagerInterface $storeManager
    ) {
        $this->visitor = $visitor;
        $this->resolver = $resolver;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * The visitor of this request (catalog visibility, the page-cache vary value).
     *
     * @return BuyerGroupAnswer
     */
    public function current(): BuyerGroupAnswer
    {
        if ($this->current !== null) {
            return $this->current;
        }
        if ($this->visitor->isExempt()) {
            return $this->current = new BuyerGroupAnswer(BuyerGroupAnswer::EXEMPT);
        }
        if (!$this->visitor->isLoggedIn()) {
            return $this->current = new BuyerGroupAnswer(BuyerGroupAnswer::GUEST);
        }

        $storeId = $this->storeId();
        if (!$this->groupSelected($this->visitor->getCustomerGroupId(), $storeId)) {
            return $this->current = new BuyerGroupAnswer(BuyerGroupAnswer::GUEST);
        }

        return $this->current = $this->resolver->resolve(
            $storeId,
            (int) $this->visitor->getCustomerId(),
            $this->visitor->getCustomerEmail()
        );
    }

    /**
     * The customer a cart belongs to (payment and shipping restrictions, shipping discounts,
     * tax exemption). Read from the cart, not the session, so the answer is the same on the
     * storefront and over REST or GraphQL.
     *
     * @param CartInterface $quote
     * @return BuyerGroupAnswer
     */
    public function forQuote(CartInterface $quote): BuyerGroupAnswer
    {
        if ($this->visitor->isExempt()) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::EXEMPT);
        }

        if ($quote->getCustomerIsGuest()) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::GUEST);
        }
        $customer = $quote->getCustomer();
        $customerId = $customer !== null ? (int) $customer->getId() : 0;
        if ($customerId < 1) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::GUEST);
        }

        $storeId = (int) $quote->getStoreId();
        if (!$this->groupSelected((int) $customer->getGroupId(), $storeId)) {
            return new BuyerGroupAnswer(BuyerGroupAnswer::GUEST);
        }

        $email = trim((string) $customer->getEmail());

        return $this->resolver->resolve($storeId, $customerId, $email);
    }

    /**
     * @param int $groupId
     * @param int $storeId
     * @return bool
     */
    private function groupSelected(int $groupId, int $storeId): bool
    {
        $selected = $this->config->getCustomerGroups($storeId);

        return $selected === [] || in_array($groupId, $selected, true);
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
