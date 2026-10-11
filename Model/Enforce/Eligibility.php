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
 * THE CART'S CURRENCY ON A NEW CART. Magento gives a quote its `quote_currency_code` only when
 * the quote is first saved (Quote::beforeSave(), Magento_Quote Model/Quote.php:818-848, read in
 * vendor 2.4.8-p5): the forced currency when one is set, else the store's current currency. A
 * signed-in customer with no active cart gets a fresh, UNSAVED quote from
 * Checkout\Model\Session::getQuote(), and the first Quote::addProduct() runs on it before
 * Checkout\Model\Cart::save(). Reading only the stored code made that first add skip every
 * check (devstore Q/A finding #39: qty 12 against a limit of 2 to 10 was accepted into an empty
 * cart, every later add refused). So an empty code is resolved exactly the way beforeSave() will
 * resolve it a moment later.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Directory\Model\Currency;
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
        $currency = $this->currency($quote);
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

    /**
     * The cart's currency: the stored code, or the one Quote::beforeSave() is about to store.
     *
     * @param Quote $quote
     * @return string Upper-case ISO code, or '' when Magento has none either.
     */
    private function currency(Quote $quote): string
    {
        $code = strtoupper(trim((string) $quote->getQuoteCurrencyCode()));
        if ($code !== '') {
            return $code;
        }

        $forced = $quote->getData('forced_currency');
        if ($forced instanceof Currency) {
            return strtoupper(trim((string) $forced->getCode()));
        }

        try {
            $store = $quote->getStore();
        } catch (\Exception $e) {
            return '';
        }

        return strtoupper(trim((string) $store->getCurrentCurrencyCode()));
    }
}
