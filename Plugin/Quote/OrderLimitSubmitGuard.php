<?php
/**
 * Refuses an order that breaks a TackQuote order limit: every quantity bound the product pages
 * state and every order-total bound in the cart's currency.
 *
 * A `before` plugin on Magento\Quote\Model\QuoteManagement::submit() (QuoteManagement.php:556),
 * ordered after the quote-only guard (10) and after the account-price re-resolution (20), so the
 * order total it checks is the one that will be charged. It is the backstop for every cart
 * change that reaches neither Quote::addProduct() nor a cart-update guard.
 *
 * An INTACT accepted-quote cart (Model\QuoteCheckout\QuotedCart::isIntact()) is not checked: the
 * seller accepted those quantities and that total on the quote. A cart that only contains a
 * quoted line is refused before this runs (Plugin\Quote\QuoteCheckoutSubmitGuard, sortOrder 5).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;

class OrderLimitSubmitGuard
{
    /**
     * @var OrderLimitChecker
     */
    private $checker;

    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @param OrderLimitChecker $checker
     * @param QuotedCart $quotedCart
     */
    public function __construct(OrderLimitChecker $checker, QuotedCart $quotedCart)
    {
        $this->checker = $checker;
        $this->quotedCart = $quotedCart;
    }

    /**
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        if ($this->quotedCart->isIntact($quote)) {
            return;
        }
        $this->checker->assertCart($quote);
    }
}
