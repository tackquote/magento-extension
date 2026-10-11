<?php
/**
 * At order submit, ask TackQuote again and charge exactly what it answers now.
 *
 * A `before` plugin on Magento\Quote\Model\QuoteManagement::submit() (QuoteManagement.php:556),
 * the interception point every order placement reaches (Luma checkout's `payment-information`,
 * GraphQL placeOrder, REST, PayPal Express review), declared beside the quote-only submit guard
 * and ordered after it (sortOrder 20 > 10): a cart quote-only mode refuses is never priced.
 *
 * submit() does not collect totals itself; it converts the quote's lines and address totals
 * into the order as they stand (submitQuote(), resolveItems() at :657). So after the fresh
 * answer is applied (Model\Enforce\CustomPriceApplier::apply($quote, true), which bypasses every
 * cache), the totals are collected again whenever any line's price changed, BEFORE conversion.
 * A cached price from a minute ago can therefore never reach an order.
 *
 * FAILURE — TackQuote unreachable, an unusable answer, a currency that cannot be confirmed:
 * every line this module priced goes back to Magento's own price, the totals are recollected,
 * the order is placed at the store's prices, and Observer\AccountPriceOrderComment records on
 * the order that the account price could not be confirmed. The order is never refused for it,
 * and a price TackQuote did not return now is never charged.
 *
 * An unexpected exception is NOT swallowed here: it aborts the order, because placing it would
 * mean charging prices whose source is unknown.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;

class AccountPriceSubmitPlugin
{
    /**
     * @var CustomPriceApplier
     */
    private $applier;

    /**
     * @param CustomPriceApplier $applier
     */
    public function __construct(CustomPriceApplier $applier)
    {
        $this->applier = $applier;
    }

    /**
     * Signature mirrors QuoteManagement::submit(QuoteEntity $quote, $orderData = []).
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        if (!$this->applier->apply($quote, true)) {
            return;
        }

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
    }
}
