<?php
/**
 * Whose cart may TackQuote's checkout enforcement act on, and as whom?
 *
 * One answer for both enforcement features (account prices charged in the cart, order limits
 * refused), so the cart can never treat a customer differently from the product page:
 *
 *   - a SIGNED-IN storefront customer, never a guest. The B2B display only ever shows a
 *     signed-in customer anything (Controller\B2b\Display), so a guest is never charged a
 *     price or refused by a limit they were never shown;
 *   - the customer who OWNS the cart. The visitor (Model\QuoteOnly\VisitorContextInterface,
 *     bound per area: the customer session on the storefront, the Web API user context in
 *     REST, SOAP and GraphQL) must be the quote's own customer, so no request can price one
 *     customer's cart as another;
 *   - never an exempt caller. The merchant's admin and integration tokens (which include
 *     TackQuote converting an accepted quote) keep the cart exactly as they built it;
 *   - inside the merchant's B2B customer-group filter, the same filter the display uses
 *     (`tackquote_quotes/b2b/customer_groups`, empty = every signed-in customer), because
 *     TackQuote finds the account by an email address Magento may never have confirmed;
 *   - with the account email the visitor context reads, the same address the display sends.
 *
 * Anything else returns null, which means "Magento's own behaviour, untouched".
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class Eligibility
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var VisitorContextInterface
     */
    private $visitor;

    /**
     * @param Config $config
     * @param VisitorContextInterface $visitor Bound per area (see etc/<area>/di.xml).
     */
    public function __construct(Config $config, VisitorContextInterface $visitor)
    {
        $this->config = $config;
        $this->visitor = $visitor;
    }

    /**
     * The buyer this cart may be enforced for, or null.
     *
     * @param Quote $quote
     * @return array{storeId: int, customerId: int, email: string, currency: string}|null
     */
    public function buyer(Quote $quote): ?array
    {
        if ($this->visitor->isExempt() || !$this->visitor->isLoggedIn()) {
            return null;
        }

        $customerId = (int) $this->visitor->getCustomerId();
        if ($customerId < 1 || (int) $quote->getCustomerId() !== $customerId) {
            return null;
        }

        $storeId = (int) $quote->getStoreId();
        $allowed = QuoteOnlyRules::normaliseGroups($this->config->getB2bCustomerGroups($storeId));
        if ($allowed !== [] && !in_array($this->visitor->getCustomerGroupId(), $allowed, true)) {
            return null;
        }

        $email = strtolower(trim($this->visitor->getCustomerEmail()));
        $currency = strtoupper(trim((string) $quote->getQuoteCurrencyCode()));
        if ($email === '' || $currency === '') {
            return null;
        }

        return [
            'storeId' => $storeId,
            'customerId' => $customerId,
            'email' => $email,
            'currency' => $currency,
        ];
    }
}
