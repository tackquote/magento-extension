<?php
/**
 * Tax-exempt buyers: a cart whose customer TackQuote marks tax exempt is taxed as the customer
 * tax class the merchant chose for exempt buyers (one that no tax rule uses), so no tax is
 * charged.
 *
 * WHY THIS POINT. Magento charges tax by the CUSTOMER TAX CLASS: a tax rule pairs customer
 * classes with product classes and rates, and a class that no rule names gets no rate (Adobe
 * Commerce user guide, Stores > Tax Rules). The cart's class comes from
 * Magento\Quote\Model\Quote::getCustomerTaxClassId(), which re-reads it from the customer group
 * on every call (Quote.php:1104-1135, Magento 2.4-develop), and every quote tax collector
 * (subtotal, shipping, tax) builds its QuoteDetails from exactly that call:
 * CommonTaxCollector::prepareQuoteDetails() sets the customer tax class key to
 * `$address->getQuote()->getCustomerTaxClassId()` (Tax/Model/Sales/Total/Quote/
 * CommonTaxCollector.php:543-548), and TaxCalculation::calculateTax() resolves rates from that
 * class (Tax/Model/TaxCalculation.php:158-166). So one after-plugin here changes what the cart
 * and the order charge, and nothing else.
 *
 * CACHE-SAFE BY CONSTRUCTION. Catalogue price display does NOT go through the cart: it builds
 * its own QuoteDetails from the customer group's class (Catalog/Helper/Data.php:552-576). An
 * exemption therefore never changes a page that the full-page cache could hand to another
 * shopper. The cost: on a store that DISPLAYS prices including tax, an exempt buyer still sees
 * catalogue prices including tax; the cart and checkout charge none.
 *
 * NOTHING IS STORED. The customer's group, and the group's class, are never changed (the
 * WooCommerce plugin likewise never saves the exemption: class-tack-tax-exempt.php). An
 * exemption TackQuote stops confirming stops applying at the next totals collection after the
 * 60-second buyer-group cache expires.
 *
 * FAILS CLOSED: only `taxExempt: true` exempts (Model\Groups\BuyerGroupResolver::map()). An
 * outage, a missing field, a guest, no class chosen, or the switch off: tax is charged.
 * Exempt callers (admin, integrations) are untouched: the plugin is declared only in the
 * frontend, webapi_rest, webapi_soap and graphql areas, and Audience treats admin and
 * integration tokens there as exempt.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Tax;

use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\Groups\Audience;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;

class TaxExemptCustomerClass
{
    /**
     * @var GroupRulesConfig
     */
    private $config;

    /**
     * @var Audience
     */
    private $audience;

    /**
     * @param GroupRulesConfig $config
     * @param Audience $audience
     */
    public function __construct(GroupRulesConfig $config, Audience $audience)
    {
        $this->config = $config;
        $this->audience = $audience;
    }

    /**
     * Use the exempt customer tax class for a tax-exempt buyer.
     *
     * @param Quote $subject
     * @param mixed $result The class from the customer group.
     * @return mixed
     */
    public function afterGetCustomerTaxClassId(Quote $subject, $result)
    {
        $exemptClass = $this->config->getTaxExemptClassId((int) $subject->getStoreId());
        if ($exemptClass <= 0 || (int) $subject->getData('customer_id') < 1) {
            return $result;
        }

        $answer = $this->audience->forQuote($subject);
        if ($answer->isExempt() || !$answer->isTaxExempt()) {
            return $result;
        }

        return $exemptClass;
    }
}
