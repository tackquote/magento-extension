<?php
/**
 * The checkout page with a broken accepted quote in the cart: the quote leaves the cart whole
 * and the shopper is told why, on the cart page.
 *
 * The WooCommerce plugin's `check_cart()` drop (Tack_Quote_Checkout::drop_quote_lines()). An
 * `around` plugin on Magento\Checkout\Controller\Index\Index::execute() (frontend only), ordered
 * before the quote-only checkout guard (5 < 10), for the same reason that guard is an `around`:
 * a refusal has to REPLACE the controller's result. An INTACT quoted cart proceeds, and the
 * quote-only checkout guard lets it through (Plugin\Checkout\QuoteOnlyCheckoutGuard).
 *
 * The order itself is guarded by Plugin\Quote\QuoteCheckoutSubmitGuard; this page guard is what
 * gives the shopper an explanation instead of a failed payment step.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;

class QuoteCheckoutPageGuard
{
    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @param QuotedCart $quotedCart
     * @param CheckoutSession $checkoutSession Injected as a Proxy (etc/frontend/di.xml).
     * @param CartRepositoryInterface $cartRepository
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     */
    public function __construct(
        QuotedCart $quotedCart,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        RedirectFactory $redirectFactory,
        MessageManager $messageManager
    ) {
        $this->quotedCart = $quotedCart;
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * Send a cart with a broken accepted quote back to the cart page instead of the checkout.
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
            return $proceed();
        }
        if (!$cart instanceof Quote || !$this->quotedCart->hasQuotedLines($cart)
            || $this->quotedCart->isIntact($cart)
        ) {
            return $proceed();
        }

        $this->quotedCart->dropQuotedLines($cart);
        $cart->setTotalsCollectedFlag(false);
        $cart->collectTotals();
        $this->cartRepository->save($cart);
        $this->messageManager->addErrorMessage(
            __(
                'Your cart no longer matched the accepted quote, so the quote was removed. Open the quote '
                . 'again to check it out.'
            )
        );

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
