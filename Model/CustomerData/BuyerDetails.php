<?php
/**
 * The signed-in customer's own details for the quote form's "Your details" step: first name,
 * last name, account email and the default billing address's telephone.
 *
 * A Magento customer-data SECTION (`tackquote-buyer`, registered in etc/frontend/di.xml), not
 * Block\QuoteList's JSON config: that block is rendered into full-page-cached HTML, which
 * Magento shares between every customer of the same customer group, so a name or email put
 * there would be served to other shoppers. Sections are Magento's mechanism for private
 * content: they travel in the `customer/section/load` request Magento_Customer already makes
 * for its own sections, are kept per browser, and are dropped on login, logout and account
 * edit (Magento_Customer etc/frontend/sections.xml, `*`) and on an address save
 * (etc/frontend/sections.xml here).
 *
 * Values come from Magento's own customer record (CurrentCustomer, as the core `customer`
 * section reads it), never from TackQuote and never from anything posted. A guest gets [].
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;

class BuyerDetails implements SectionSourceInterface
{
    /** The section name, as the storefront script reads it (customerData.get()). */
    public const SECTION = 'tackquote-buyer';

    /**
     * @var CurrentCustomer
     */
    private $currentCustomer;

    /**
     * @param CurrentCustomer $currentCustomer
     */
    public function __construct(CurrentCustomer $currentCustomer)
    {
        $this->currentCustomer = $currentCustomer;
    }

    /**
     * @inheritdoc
     */
    public function getSectionData()
    {
        if (!$this->currentCustomer->getCustomerId()) {
            return [];
        }
        $customer = $this->currentCustomer->getCustomer();
        $telephone = '';
        $billingId = (string) $customer->getDefaultBilling();
        foreach ((array) $customer->getAddresses() as $address) {
            if ($billingId !== '' && (string) $address->getId() === $billingId) {
                $telephone = (string) $address->getTelephone();
            }
        }

        return [
            'firstname' => (string) $customer->getFirstname(),
            'lastname' => (string) $customer->getLastname(),
            'email' => (string) $customer->getEmail(),
            'telephone' => $telephone,
        ];
    }
}
