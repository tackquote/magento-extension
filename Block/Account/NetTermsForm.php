<?php
/**
 * The "Net terms" tab: the customer's approved terms and credit, a pending or declined
 * notice, or the application form. Account pages only; not cacheable (layout).
 *
 * Reads the same cached standing as the checkout method (Model\Payment\NetTermsStanding), so
 * the tab and checkout never disagree within a minute.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Account;

use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\CreditApplicationBuilder;
use TackQuote\Quotes\Model\Forms\FormDefinition;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Payment\NetTermsEligibility;
use TackQuote\Quotes\Model\Payment\NetTermsStanding;

class NetTermsForm extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var NetTermsStanding
     */
    private $standing;

    /**
     * @var NetTermsEligibility
     */
    private $eligibility;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @param Context $context
     * @param Config $config
     * @param IdentityResolver $identities
     * @param NetTermsStanding $standing
     * @param NetTermsEligibility $eligibility
     * @param FormDefinition $definition
     * @param Session $customerSession
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        IdentityResolver $identities,
        NetTermsStanding $standing,
        NetTermsEligibility $eligibility,
        FormDefinition $definition,
        Session $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->identities = $identities;
        $this->standing = $standing;
        $this->eligibility = $eligibility;
        $this->definition = $definition;
        $this->customerSession = $customerSession;
    }

    /**
     * Whether this store can take applications from this customer at all.
     *
     * @return bool
     */
    public function canApply(): bool
    {
        return $this->customerSession->isLoggedIn() && $this->identity()->carriesCustomerId();
    }

    /**
     * The customer's net terms standing, for the template.
     *
     * NetTermsEligibility::view() of the customer's standing (`state`: none, pending,
     * declined or active). `none` when it cannot be read.
     *
     * @return array{state: string, termsDays: int, creditLimit: string, available: string, currency: string}
     */
    public function getView(): array
    {
        $answer = $this->canApply() ? $this->standing->read($this->identity(), $this->storeId()) : null;

        return $this->eligibility->view($answer);
    }

    /**
     * A money amount in the line's currency, for display.
     *
     * @param string $amount
     * @param string $currency
     * @return string
     */
    public function formatMoney(string $amount, string $currency): string
    {
        if (!is_numeric($amount)) {
            return '';
        }

        return $currency . ' ' . number_format((float) $amount, 2);
    }

    /**
     * Payment terms, in days, the applicant can choose from.
     *
     * @return int[]
     */
    public function getTermsDays(): array
    {
        return CreditApplicationBuilder::TERMS_DAYS;
    }

    /**
     * Number of trade reference rows the form offers.
     *
     * @return int
     */
    public function getReferenceCount(): int
    {
        return CreditApplicationBuilder::MAX_REFERENCES;
    }

    /**
     * Address parts with their labels, for the billing address inputs.
     *
     * @return array<string, string>
     */
    public function getAddressParts(): array
    {
        $parts = [];
        foreach (FormDefinition::ADDRESS_PARTS as $part) {
            $parts[$part] = $this->definition->addressPartLabel($part);
        }

        return $parts;
    }

    /**
     * The account email applications are filed under (shown, never editable).
     *
     * @return string
     */
    public function getAccountEmail(): string
    {
        return $this->identity()->getEmail();
    }

    /**
     * URL the application form posts to.
     *
     * @return string
     */
    public function getActionUrl(): string
    {
        return $this->getUrl('tackquote/account/nettermspost');
    }

    /**
     * Identity of the signed-in customer, as TackQuote knows it.
     *
     * @return \TackQuote\Quotes\Model\Forms\AccountIdentity
     */
    private function identity()
    {
        $customer = $this->customerSession->getCustomerData();

        return $this->identities->forCustomer(
            (int) $this->customerSession->getCustomerId(),
            $customer ? (string) $customer->getEmail() : '',
            $this->storeId()
        );
    }

    /**
     * Id of the current store, or null when it cannot be read.
     *
     * @return int|null
     */
    private function storeId(): ?int
    {
        try {
            return (int) $this->_storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return null;
        }
    }
}
