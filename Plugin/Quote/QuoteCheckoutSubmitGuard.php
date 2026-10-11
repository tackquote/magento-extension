<?php
/**
 * A cart that holds an accepted quote becomes an order only while it is exactly that quote.
 *
 * A `before` plugin on Magento\Quote\Model\QuoteManagement::submit(), the interception point
 * every order placement reaches (Plugin\Quote\QuoteOnlySubmitGuard lists them), declared in the
 * frontend, REST, SOAP and GraphQL areas and ordered FIRST (5): before the quote-only guard (10),
 * the account-price re-resolution (20) and the order-limit guard (30). Those three exempt an
 * INTACT quoted cart (Model\QuoteCheckout\QuotedCart::isIntact()); this guard is what makes the
 * exemption safe, by refusing a cart that holds a quoted line but is no longer intact: a line
 * removed or added, a quantity or price changed (GraphQL `updateCartItems` included), or the
 * cart's currency switched since the quote was opened.
 *
 * The WooCommerce plugin's `woocommerce_check_cart_items` integrity check
 * (Tack_Quote_Checkout::check_cart()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;

class QuoteCheckoutSubmitGuard
{
    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @param QuotedCart $quotedCart
     */
    public function __construct(QuotedCart $quotedCart)
    {
        $this->quotedCart = $quotedCart;
    }

    /**
     * Signature mirrors QuoteManagement::submit(QuoteEntity $quote, $orderData = []).
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        if (!$this->quotedCart->hasQuotedLines($quote) || $this->quotedCart->isIntact($quote)) {
            return;
        }

        throw new LocalizedException(
            __(
                'Your cart no longer matches the accepted quote. Remove the quote from your cart, or open '
                . 'the quote again to check it out.'
            )
        );
    }
}
