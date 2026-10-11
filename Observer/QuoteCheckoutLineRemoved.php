<?php
/**
 * An accepted quote leaves the cart whole: removing one of its lines removes the rest.
 *
 * The WooCommerce plugin's `woocommerce_cart_item_removed` handler
 * (Tack_Quote_Checkout::on_item_removed()). `sales_quote_remove_item` is dispatched by
 * Magento\Quote\Model\Quote::removeItem() (magento2 2.4-develop Quote.php) with `quote_item`, the
 * one method every removal reaches: the cart page's delete and "qty 0" (Checkout\Model\Cart::
 * updateItems() -> removeItem()), the mini cart, and the REST/GraphQL item deletes
 * (CartItemRepository::deleteById()). The removed line is only MARKED deleted there; the caller
 * saves the quote, which then saves every removal made here too.
 *
 * Model\QuoteCheckout\QuotedCart::dropQuotedLines() removes the rest, with its own "dropping"
 * flag so the removals it makes do not each run this observer again. A quote that is half in
 * the cart could never be checked out anyway (Plugin\Quote\QuoteCheckoutSubmitGuard); taking it
 * out whole is what tells the shopper so.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

class QuoteCheckoutLineRemoved implements ObserverInterface
{
    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @param QuotedCart $quotedCart
     * @param QuotedLine $quotedLine
     */
    public function __construct(QuotedCart $quotedCart, QuotedLine $quotedLine)
    {
        $this->quotedCart = $quotedCart;
        $this->quotedLine = $quotedLine;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->quotedCart->isDropping() || $this->quotedCart->isBuilding()) {
            return;
        }
        $item = $observer->getEvent()->getData('quote_item');
        if (!$item instanceof AbstractItem || !$this->quotedLine->isMarked($item)) {
            return;
        }
        $quote = $item->getQuote();
        if ($quote instanceof Quote) {
            $this->quotedCart->dropQuotedLines($quote);
        }
    }
}
