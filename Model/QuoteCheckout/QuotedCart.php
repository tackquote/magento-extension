<?php
/**
 * Is this cart an accepted TackQuote quote, and is it still exactly that quote?
 *
 * One instance per request (Magento's object manager shares a constructor-injected instance),
 * so the "building" flag set by Model\QuoteCheckout\CartBuilder is seen by every guard that
 * intercepts the cart while the quote is being put into it.
 *
 * INTACT is the one rule every exemption and every refusal reads:
 *
 *   - the cart has at least one visible line, and EVERY visible line carries a well-formed
 *     quote marker (Model\QuoteCheckout\QuotedLine), all naming the same reference, quote
 *     number, currency and line count;
 *   - the number of lines is the quote's (a removed line breaks it);
 *   - each line's quantity is the accepted quantity and its custom price is the quoted price;
 *   - the cart's base and quote currency are the quote's.
 *
 * Only an INTACT cart is exempt from quote-only mode and the order-limit guards (the WooCommerce
 * plugin's `Tack_Quote_Checkout::holds_product()` + `check_cart()`); a cart that merely contains
 * a quoted line is not, so tampering with a quoted cart can never open the store.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;

class QuotedCart
{
    /** Two prices closer than this are the same price (DECIMAL(12,4) columns). */
    private const PRICE_EPSILON = 0.00005;

    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @var bool
     */
    private $building = false;

    /**
     * @var bool
     */
    private $dropping = false;

    /**
     * @param QuotedLine $quotedLine
     */
    public function __construct(QuotedLine $quotedLine)
    {
        $this->quotedLine = $quotedLine;
    }

    /**
     * Set while Model\QuoteCheckout\CartBuilder adds the quote's lines.
     *
     * @return void
     */
    public function startBuilding(): void
    {
        $this->building = true;
    }

    /**
     * @return void
     */
    public function stopBuilding(): void
    {
        $this->building = false;
    }

    /**
     * @return bool
     */
    public function isBuilding(): bool
    {
        return $this->building;
    }

    /**
     * Set while Observer\QuoteCheckoutLineRemoved removes the rest of a quote.
     *
     * @param bool $dropping
     * @return void
     */
    public function setDropping(bool $dropping): void
    {
        $this->dropping = $dropping;
    }

    /**
     * @return bool
     */
    public function isDropping(): bool
    {
        return $this->dropping;
    }

    /**
     * Whether any visible line names a quote (even a damaged marker).
     *
     * @param Quote $quote
     * @return bool
     */
    public function hasQuotedLines(Quote $quote): bool
    {
        foreach ((array) $quote->getAllVisibleItems() as $item) {
            if ($item instanceof AbstractItem && $this->quotedLine->isMarked($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule in the class comment.
     *
     * @param Quote $quote
     * @return bool
     */
    public function isIntact(Quote $quote): bool
    {
        $items = (array) $quote->getAllVisibleItems();
        if ($items === []) {
            return false;
        }

        $first = null;
        foreach ($items as $item) {
            if (!$item instanceof AbstractItem) {
                return false;
            }
            $marker = $this->quotedLine->read($item);
            if ($marker === null) {
                return false;
            }
            if ($first === null) {
                $first = $marker;
            } elseif ($marker['ref'] !== $first['ref'] || $marker['number'] !== $first['number']
                || $marker['currency'] !== $first['currency'] || $marker['lines'] !== $first['lines']
            ) {
                return false;
            }
            if (abs((float) $item->getQty() - $marker['qty']) > 0.0001) {
                return false;
            }
            $custom = $item->getData('custom_price');
            if ($custom === null || !is_numeric($custom)
                || abs((float) $custom - $marker['price']) >= self::PRICE_EPSILON
            ) {
                return false;
            }
        }

        if (count($items) !== $first['lines']) {
            return false;
        }

        return (string) $quote->getData('base_currency_code') === $first['currency']
            && (string) $quote->getData('quote_currency_code') === $first['currency'];
    }

    /**
     * The quote's checkout reference, when the cart is intact.
     *
     * @param Quote $quote
     * @return string|null
     */
    public function ref(Quote $quote): ?string
    {
        if (!$this->isIntact($quote)) {
            return null;
        }
        $items = (array) $quote->getAllVisibleItems();
        $marker = $this->quotedLine->read(reset($items));

        return $marker['ref'] ?? null;
    }

    /**
     * The ids of every visible line that names a quote.
     *
     * @param Quote $quote
     * @return int[]
     */
    public function quotedItemIds(Quote $quote): array
    {
        $ids = [];
        foreach ((array) $quote->getAllVisibleItems() as $item) {
            if ($item instanceof AbstractItem && $this->quotedLine->isMarked($item) && $item->getId()) {
                $ids[] = (int) $item->getId();
            }
        }

        return $ids;
    }

    /**
     * Take every quoted line out of the cart (a broken quote leaves whole; the shopper's own
     * lines, if any, stay).
     *
     * @param Quote $quote
     * @return bool Whether anything was removed.
     */
    public function dropQuotedLines(Quote $quote): bool
    {
        $ids = $this->quotedItemIds($quote);
        if ($ids === []) {
            return false;
        }
        $this->dropping = true;
        try {
            foreach ($ids as $id) {
                $quote->removeItem($id);
            }
        } finally {
            $this->dropping = false;
        }

        return true;
    }
}
