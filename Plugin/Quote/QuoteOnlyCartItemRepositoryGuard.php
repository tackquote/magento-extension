<?php
/**
 * Quote-only mode — the cart-item SERVICE CONTRACT (REST, SOAP, GraphQL).
 *
 * Magento\Quote\Api\CartItemRepositoryInterface::save() is what `POST /V1/carts/mine/items`,
 * `PUT /V1/carts/mine/items/{itemId}` and their guest-cart twins run;
 * GuestCartItemRepository::save() unmasks the cart id and delegates to it
 * (GuestCartItemRepository.php:61-66, Magento 2.4.8), so one plugin on the interface covers
 * both. Plugins declared on an interface apply to its implementation
 * (developer.adobe.com/commerce/php/development/components/plugins).
 *
 * THE STOREFRONT DOES NOT GO THROUGH IT. Checked, not assumed: `checkout/cart/add` reaches the
 * cart through Magento\Checkout\Model\Cart::addProduct() -> Quote::addProduct(), and the cart
 * page's quantity update through Cart::updateItems(); neither touches this repository. Those
 * paths are guarded by Plugin\Quote\QuoteOnlyCartGuard and
 * Plugin\Checkout\QuoteOnlyExistingCartGuard.
 *
 * WHAT THIS ADDS. A NEW line still reaches Quote::addProduct() (CartItemPersister.php:98), where
 * QuoteOnlyCartGuard refuses it — so this plugin does not duplicate that. An EXISTING line is
 * different: CartItemPersister sets the new quantity directly with $currentItem->setQty()
 * (CartItemPersister.php:84) and never calls addProduct(), so without this a cart that
 * predates the switch could be inflated over REST. Increases are refused; decreases stay
 * allowed, for the same reason as on the cart page (QuoteOnlyExistingCartGuard).
 *
 * GraphQL `updateCartItems` sets the quantity on the item itself and does not use this
 * repository (QuoteGraphQl/Model/Cart/UpdateCartItem.php:105-114), so it is not refused here.
 * That cart still cannot become an order: Plugin\Quote\QuoteOnlySubmitGuard refuses
 * placement. README "Known limitations" says so.
 *
 * Declared in etc/webapi_rest, etc/webapi_soap and etc/graphql di.xml only; admin and
 * integration callers are exempt through Model\QuoteOnly\ApiVisitorContext.
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
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyCartItemRepositoryGuard
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(QuoteOnlyMode $quoteOnlyMode, CartRepositoryInterface $cartRepository)
    {
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->cartRepository = $cartRepository;
    }

    /**
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
            // A new line: refused (or not) inside Quote::addProduct() by QuoteOnlyCartGuard.
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
        if (!$current) {
            return;
        }

        if ((float) $cartItem->getQty() <= (float) $current->getQty()) {
            return;
        }

        if ($this->quoteOnlyMode->isActive()
            || $this->quoteOnlyMode->isProductIdQuoteOnly((int) $current->getProductId())
        ) {
            throw new LocalizedException(
                __(
                    'This item is available on quote only. Its quantity cannot be increased — '
                    . 'request a quote instead.'
                )
            );
        }
    }
}
