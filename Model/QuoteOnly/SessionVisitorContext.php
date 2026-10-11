<?php
/**
 * The storefront visitor, read from the customer session (frontend area only — see
 * etc/frontend/di.xml).
 *
 * The session is injected as a Proxy there, so constructing this never starts a session;
 * QuoteOnlyMode only asks once the cheap store-config checks say the mode might apply.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

use Magento\Customer\Model\Session as CustomerSession;

class SessionVisitorContext implements VisitorContextInterface
{
    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param CustomerSession $customerSession
     */
    public function __construct(CustomerSession $customerSession)
    {
        $this->customerSession = $customerSession;
    }

    /**
     * Whether the visitor is exempt from quote-only mode.
     *
     * A storefront shopper is never exempt. The admin is exempt structurally instead:
     * adminhtml never loads etc/frontend/di.xml, so this class is not used there.
     *
     * @return bool
     */
    public function isExempt(): bool
    {
        return false;
    }

    /**
     * Whether the visitor is a signed-in customer.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return (bool) $this->customerSession->isLoggedIn();
    }

    /**
     * Customer group of the visitor.
     *
     * @return int
     */
    public function getCustomerGroupId(): int
    {
        return (int) $this->customerSession->getCustomerGroupId();
    }

    /**
     * Customer id of the visitor, or null for a guest.
     *
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        $id = (int) $this->customerSession->getCustomerId();

        return $id > 0 ? $id : null;
    }

    /**
     * Email address of the visitor, or '' for a guest.
     *
     * @return string
     */
    public function getCustomerEmail(): string
    {
        if (!$this->isLoggedIn()) {
            return '';
        }

        try {
            // The service-contract DTO (CustomerInterface::getEmail), as Controller\B2b\Display
            // reads it.
            return trim((string) $this->customerSession->getCustomerData()->getEmail());
        } catch (\Exception $e) {
            return '';
        }
    }
}
