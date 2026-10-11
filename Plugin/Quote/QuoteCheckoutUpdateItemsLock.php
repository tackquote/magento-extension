<?php
/**
 * The quantities of an accepted quote cannot be changed in the store (cart page and mini cart).
 *
 * The WooCommerce plugin's locked quantities (Tack_Quote_Checkout::refuse_quantity_update()). A
 * `before` plugin on Magento\Checkout\Model\Cart::updateItems() (module-checkout Model/Cart.php,
 * frontend only), ordered before the quote-only and order-limit guards on the same method
 * (5 < 10, 20). `$data` is `[itemId => ['qty' => n, 'remove' => ...], ...]`, read as core reads
 * it: a `remove` flag or a quantity of '0' REMOVES the line, which is allowed (the rest of the
 * quote then leaves with it, Observer\QuoteCheckoutLineRemoved). Any other quantity than the
 * accepted one is refused for the whole update. The cart page posts every line's quantity on
 * "Update Shopping Cart", so an unchanged quoted line passes.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

class QuoteCheckoutUpdateItemsLock
{
    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @param QuotedLine $quotedLine
     */
    public function __construct(QuotedLine $quotedLine)
    {
        $this->quotedLine = $quotedLine;
    }

    /**
     * Refuse a quantity change on a quoted line.
     *
     * @param Cart $subject
     * @param mixed $data
     * @return void
     * @throws LocalizedException
     */
    public function beforeUpdateItems(Cart $subject, $data): void
    {
        if (!is_array($data)) {
            return;
        }
        $quote = $subject->getQuote();
        if (!$quote instanceof Quote) {
            return;
        }

        foreach ($data as $itemId => $itemInfo) {
            $item = $quote->getItemById($itemId);
            if (!$item instanceof AbstractItem || !is_array($itemInfo) || !$this->quotedLine->isMarked($item)) {
                continue;
            }
            $qty = $itemInfo['qty'] ?? null;
            if (!empty($itemInfo['remove']) || (is_numeric($qty) && (float) $qty === 0.0)) {
                // Core removes the line (`$itemInfo['qty'] == '0'`, Cart::updateItems()).
                continue;
            }
            if (!isset($itemInfo['qty'])) {
                continue;
            }
            $marker = $this->quotedLine->read($item);
            $locked = $marker !== null ? (float) $marker['qty'] : (float) $item->getQty();
            if (!is_numeric($itemInfo['qty']) || abs((float) $itemInfo['qty'] - $locked) > 0.0001) {
                throw new LocalizedException(self::message());
            }
        }
    }

    // phpcs:disable Magento2.Functions.StaticFunction -- one message shared with the Web API lock
    /**
     * Message shown when a quoted line's quantity cannot be changed.
     *
     * @return \Magento\Framework\Phrase
     */
    public static function message()
    {
        return __(
            'The quantities of an accepted quote cannot be changed in the store. Please ask the seller '
            . 'to revise the quote.'
        );
    }
    // phpcs:enable Magento2.Functions.StaticFunction
}
