<?php
/**
 * Quote-only mode — the CHECKOUT route.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * STANDING DECISION ON THE CHECKOUT ROUTE
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * The cart cannot grow (Plugin\Quote\QuoteOnlyCartGuard) and an existing one cannot be
 * increased (Plugin\Checkout\QuoteOnlyExistingCartGuard), but a cart that predates the
 * switch could still be carried to checkout and turned into an order. So `checkout/index`
 * is refused too, and the shopper is redirected to the cart page with a stated reason rather
 * than to a blank or broken checkout.
 *
 * Redirect-with-message rather than a 404 or an exception, because Magento has a message bus
 * and using it is the difference between "the shop is broken" and "the shop works this way".
 * The cart page is the destination on purpose: it is where the shopper's items still are,
 * and — because the mode also adds the `tackquote_quote_only_cart` layout handle
 * (Observer\QuoteOnlyLayoutHandle) — it is where a Request a Quote CTA is rendered for them.
 *
 * WHY A CONTROLLER PLUGIN HERE AND A MODEL PLUGIN FOR THE CART. The cart guard deliberately
 * avoids controllers because a product can enter a cart through many routes. Checkout is the
 * opposite shape: `Magento\Checkout\Controller\Index\Index` IS the storefront checkout, and
 * intercepting it is both precise and self-explanatory. It is a concrete, non-final public
 * `execute()`, which is what Magento's interception requires.
 *
 * THE ORDER ITSELF. Magento's Luma checkout places the order over REST
 * (`POST /rest/V1/guest-carts/{id}/payment-information`), in the `webapi_rest` area where
 * this controller plugin does not run. That conversion is refused by
 * Plugin\Quote\QuoteOnlySubmitGuard, declared for the frontend and API areas, which tells
 * the merchant's own integrations (TackQuote's quote conversion included) apart from a
 * shopper through UserContextInterface. This plugin stays, because refusing at the page is
 * what gives the shopper an explanation instead of a failed payment step.
 *
 * PER-PRODUCT. A cart holding a product whose own `tackquote_quote_only` flag is Yes is
 * refused too, with its own message, even when the store-wide mode is off.
 *
 * AN ACCEPTED QUOTE'S CART goes to the checkout while it is INTACT
 * (Model\QuoteCheckout\QuotedCart::isIntact()): the one legitimate checkout in a quote-only
 * store. A broken one never reaches here: Plugin\Quote\QuoteCheckoutPageGuard (ordered first)
 * takes the quote out and sends the shopper to the cart.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Checkout;

use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyCheckoutGuard
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     * @param CheckoutSession $checkoutSession Injected as a Proxy (etc/frontend/di.xml).
     * @param QuotedCart $quotedCart
     */
    public function __construct(
        QuoteOnlyMode $quoteOnlyMode,
        RedirectFactory $redirectFactory,
        MessageManager $messageManager,
        CheckoutSession $checkoutSession,
        QuotedCart $quotedCart
    ) {
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->checkoutSession = $checkoutSession;
        $this->quotedCart = $quotedCart;
    }

    /**
     * `around` rather than `before`, because a refusal has to REPLACE the controller's
     * result. A `before` plugin cannot stop `execute()` from running without throwing, and
     * throwing out of a storefront controller produces an error page instead of an
     * explanation.
     *
     * @param CheckoutIndex $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(CheckoutIndex $subject, callable $proceed)
    {
        try {
            $cart = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            $cart = null;
        }
        if ($cart !== null && $this->quotedCart->isIntact($cart)) {
            return $proceed();
        }

        if ($this->quoteOnlyMode->isActive()) {
            $this->messageManager->addErrorMessage(
                __('This store is quote-only. Request a quote for your items and we will send you a price.')
            );

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        if ($cart === null) {
            // Core's own checkout cannot load this cart either and handles that itself.
            return $proceed();
        }

        if ($this->quoteOnlyMode->cartHasQuoteOnlyProduct($cart)) {
            $this->messageManager->addErrorMessage(
                __(
                    'Your basket holds a product that is available on quote only. Remove it, or '
                    . 'request a quote for it, before checking out.'
                )
            );

            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        return $proceed();
    }
}
