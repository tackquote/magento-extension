<?php
/**
 * Refuses a Web API quantity change on an existing cart line that would break a TackQuote order
 * limit (REST/SOAP `PUT /V1/carts/mine/items/{itemId}`, the GraphQL areas' repository saves).
 *
 * A `before` plugin on Magento\Quote\Api\CartItemRepositoryInterface::save(), declared in the
 * Web API areas beside the quote-only guard (Plugin\Quote\QuoteOnlyCartItemRepositoryGuard): an
 * existing line's quantity is set directly and never reaches Quote::addProduct(). A NEW line does
 * reach it and is checked there (Plugin\Quote\OrderLimitCartGuard). Changes that reach neither
 * (GraphQL updateCartItems) are caught at order submit (Plugin\Quote\OrderLimitSubmitGuard).
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
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;

class OrderLimitCartItemRepositoryGuard
{
    /**
     * @var OrderLimitChecker
     */
    private $checker;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @param OrderLimitChecker $checker
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(OrderLimitChecker $checker, CartRepositoryInterface $cartRepository)
    {
        $this->checker = $checker;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Check order limits before a cart item is saved through the API.
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
        if ($itemId === null || $itemId === '' || (int) $itemId < 1 || !is_numeric($cartItem->getQty())) {
            return;
        }

        try {
            $cart = $this->cartRepository->get((int) $cartItem->getQuoteId());
        } catch (\Exception $e) {
            return;
        }
        if (!$cart instanceof Quote || !$cart->getItemById((int) $itemId)) {
            return;
        }

        $this->checker->assertItemQuantities($cart, [(int) $itemId => (float) $cartItem->getQty()]);
    }
}
