<?php
/**
 * The quantities of an accepted quote cannot be changed over the Web API either.
 *
 * A `before` plugin on Magento\Quote\Api\CartItemRepositoryInterface::save() (REST/SOAP
 * `PUT /V1/carts/mine/items/{itemId}` and the guest twin; GuestCartItemRepository delegates),
 * declared in the REST, SOAP and GraphQL areas before the quote-only and order-limit guards
 * (5 < 10, 20). An existing quoted line's quantity is set directly there and never reaches
 * Quote::addProduct(); a NEW line is refused by Plugin\Quote\QuoteCheckoutCartGuard inside
 * addProduct(). GraphQL `updateCartItems` sets the quantity on the item itself without this
 * repository; that cart is no longer intact and Plugin\Quote\QuoteCheckoutSubmitGuard refuses
 * the order (README "Known limitations").
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

class QuoteCheckoutCartItemRepositoryLock
{
    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @param QuotedLine $quotedLine
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(QuotedLine $quotedLine, CartRepositoryInterface $cartRepository)
    {
        $this->quotedLine = $quotedLine;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Refuse a Web API quantity change on a quoted line.
     *
     * @param CartItemRepositoryInterface $subject
     * @param CartItemInterface $cartItem
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(CartItemRepositoryInterface $subject, CartItemInterface $cartItem): void
    {
        $itemId = $cartItem->getItemId();
        if ($itemId === null || $itemId === '' || (int) $itemId < 1) {
            return;
        }
        try {
            $cart = $this->cartRepository->get((int) $cartItem->getQuoteId());
        } catch (\Exception $e) {
            // Core raises its own NoSuchEntityException for an unknown cart a moment later.
            return;
        }
        if (!$cart instanceof Quote) {
            return;
        }
        $current = $cart->getItemById((int) $itemId);
        if (!$current instanceof AbstractItem || !$this->quotedLine->isMarked($current)) {
            return;
        }
        $marker = $this->quotedLine->read($current);
        $locked = $marker !== null ? (float) $marker['qty'] : (float) $current->getQty();
        if (!is_numeric($cartItem->getQty()) || abs((float) $cartItem->getQty() - $locked) > 0.0001) {
            throw new LocalizedException(QuoteCheckoutUpdateItemsLock::message());
        }
    }
}
