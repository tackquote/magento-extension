<?php
/**
 * Refuses a cart-page "Update Shopping Cart" that would break a TackQuote order limit.
 *
 * A `before` plugin on Magento\Checkout\Model\Cart::updateItems() (module-checkout
 * Model/Cart.php:517), frontend only, beside the quote-only guard
 * (Plugin\Checkout\QuoteOnlyExistingCartGuard) and ordered after it. `$data` is
 * `[itemId => ['qty' => n, 'remove' => ...], ...]`, read as core reads it: a `remove` flag or a
 * quantity of 0 removes the line. The cart page shows the refusal as its error message.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Checkout;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;

class OrderLimitUpdateItemsGuard
{
    /**
     * @var OrderLimitChecker
     */
    private $checker;

    /**
     * @param OrderLimitChecker $checker
     */
    public function __construct(OrderLimitChecker $checker)
    {
        $this->checker = $checker;
    }

    /**
     * Check order limits before cart quantities are updated.
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

        $quantities = [];
        foreach ($data as $itemId => $itemInfo) {
            if (!is_array($itemInfo) || !$quote->getItemById($itemId)) {
                continue;
            }
            if (!empty($itemInfo['remove'])) {
                $quantities[$itemId] = 0.0;
            } elseif (isset($itemInfo['qty']) && is_numeric($itemInfo['qty'])) {
                $quantities[$itemId] = max(0.0, (float) $itemInfo['qty']);
            }
        }

        $this->checker->assertItemQuantities($quote, $quantities);
    }
}
