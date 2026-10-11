<?php
/**
 * Does the cart charge TackQuote account prices in this store?
 *
 * The merchant's switch (`tackquote_quotes/b2b_checkout/enforce_prices`, default 0) AND a
 * working connection AND a catalog whose prices are entered EXCLUDING tax.
 *
 * WHY THE TAX CONDITION — TackQuote resolves NET unit prices (the WooCommerce plugin applies
 * them as "Net unit price resolved by TackQuote" and converts them to the store's tax basis
 * first, Tack_Tax_Basis::entry_price()). Magento reads a quote item's custom price on the
 * catalog's own basis: with "Catalog Prices: Including Tax" on, a net price would be taken as
 * gross and the order would be short by the tax. Converting net to gross needs the tax rate for
 * this customer's address, which the cart does not reliably know until checkout, so the module
 * does not guess: in such a store the cart keeps Magento's prices, and the product page keeps
 * saying so (Model\B2bDisplay\Presenter reads this same answer for its cart note).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use TackQuote\Quotes\Model\Config;

class PriceEnforcementSwitch
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Whether account prices are charged at checkout on this store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isOn(?int $storeId = null): bool
    {
        return $this->config->isEnforcePricesEnabled($storeId)
            && !$this->config->catalogPricesIncludeTax($storeId);
    }
}
