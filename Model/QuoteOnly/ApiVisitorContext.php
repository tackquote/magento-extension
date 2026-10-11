<?php
/**
 * The caller of a REST, SOAP or GraphQL request (etc/webapi_rest, etc/webapi_soap and
 * etc/graphql di.xml only).
 *
 * WHO IS EXEMPT, AND WHY THAT IS SAFE FOR QUOTE CONVERSION.
 *
 * Magento\Authorization\Model\UserContextInterface (module-authorization, `@api`) types every
 * caller: USER_TYPE_INTEGRATION = 1, USER_TYPE_ADMIN = 2, USER_TYPE_CUSTOMER = 3,
 * USER_TYPE_GUEST = 4 (UserContextInterface.php:20-23, Magento 2.4.8). The global preference
 * is CompositeUserContext (module-authorization/etc/di.xml:26), which picks the FIRST member
 * context that yields a type and an id (CompositeUserContext::getUserContext()).
 *
 *   - INTEGRATION and ADMIN are exempt. TackQuote converts an accepted quote by calling the
 *     store's REST API — `POST /V1/guest-carts` (createGuestCart, magento.service.ts:1650),
 *     `POST /V1/guest-carts/{id}/items` (:1660) and
 *     `POST /V1/guest-carts/{id}/payment-information` (setPaymentMethod, :1735) in tack
 *     apps/api/src/modules/integrations/magento/magento.service.ts — signed with the
 *     Integration's OAuth 1.0a credentials. Those endpoints are anonymous, but in webapi_rest the OAuth member
 *     (sortOrder 40) is consulted before the guest member (sortOrder 100)
 *     (module-webapi/etc/webapi_rest/di.xml:14-31), and OauthUserContext answers
 *     USER_TYPE_INTEGRATION (OauthUserContext.php:93). So the conversion is typed as an
 *     integration and passes. UNVERIFIED on a running store: see the PR's manual steps.
 *   - CUSTOMER is a signed-in shopper (token, or the Luma checkout's session in webapi_rest).
 *   - GUEST, or anything else — including no context at all — is treated as an anonymous
 *     storefront caller. Unknown fails towards the policy, never towards the exemption.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;

class ApiVisitorContext implements VisitorContextInterface
{
    /**
     * @var UserContextInterface
     */
    private $userContext;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerInterface|false|null False once a lookup failed; null before any lookup.
     */
    private $customer;

    /**
     * @param UserContextInterface $userContext
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        UserContextInterface $userContext,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->userContext = $userContext;
        $this->customerRepository = $customerRepository;
    }

    /**
     * @return bool
     */
    public function isExempt(): bool
    {
        $type = (int) $this->userContext->getUserType();

        return $type === UserContextInterface::USER_TYPE_ADMIN
            || $type === UserContextInterface::USER_TYPE_INTEGRATION;
    }

    /**
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return (int) $this->userContext->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER
            && (int) $this->userContext->getUserId() > 0;
    }

    /**
     * @return int
     */
    public function getCustomerGroupId(): int
    {
        $customer = $this->customer();

        // NOT LOGGED IN (0) for a guest or an unreadable customer: the strictest group on a
        // store whose quote-only scope names groups is exactly the public's.
        return $customer ? (int) $customer->getGroupId() : 0;
    }

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return $this->isLoggedIn() ? (int) $this->userContext->getUserId() : null;
    }

    /**
     * @return string
     */
    public function getCustomerEmail(): string
    {
        $customer = $this->customer();

        return $customer ? trim((string) $customer->getEmail()) : '';
    }

    /**
     * @return CustomerInterface|null
     */
    private function customer(): ?CustomerInterface
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        if ($this->customer === null) {
            try {
                $this->customer = $this->customerRepository->getById((int) $this->userContext->getUserId());
            } catch (\Exception $e) {
                $this->customer = false;
            }
        }

        return $this->customer instanceof CustomerInterface ? $this->customer : null;
    }
}
