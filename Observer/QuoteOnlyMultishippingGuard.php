<?php
/**
 * Quote-only mode — MULTISHIPPING ("Check Out with Multiple Addresses").
 *
 * Magento_Multishipping has its own checkout controllers under the `multishipping` route
 * (module-multishipping/etc/frontend/routes.xml) that never pass through
 * Magento\Checkout\Controller\Index\Index, so Plugin\Checkout\QuoteOnlyCheckoutGuard does
 * not see them. Its final step creates orders through the order service, not through
 * QuoteManagement::submit() — Multishipping::createOrders() hands them to
 * PlaceOrderDefault::place(), which calls OrderManagementInterface::place()
 * (Model/Checkout/Type/Multishipping.php:826, Multishipping/PlaceOrderDefault.php:39,
 * Magento 2.4.8) — so Plugin\Quote\QuoteOnlySubmitGuard does not see them either.
 *
 * WHY AN OBSERVER AND NOT A PLUGIN. The module is optional and is commonly removed from
 * composer installs. A plugin would have to name one of its classes; an observer names only
 * an event, which simply never fires when the module is absent. The front controller
 * dispatches `controller_action_predispatch_<route id>` before every action
 * (Magento\Framework\App\FrontController::dispatchPreDispatchEvents(), FrontController.php:
 * 269-281, Magento 2.4.8), and when an observer sets ActionInterface::FLAG_NO_DISPATCH it
 * returns the response WITHOUT running the action (FrontController::getActionResponse(),
 * FrontController.php:240-241). The predispatch event is the one Adobe lists for
 * "actions when predispatch events are raised"
 * (developer.adobe.com/commerce/php/development/components/events-and-observers/event-list).
 *
 * The whole route is refused — address, shipping, billing and overview steps alike — and the
 * shopper is sent to the cart page with the reason, as the single-page checkout does.
 * Declared in etc/frontend/events.xml.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyMultishippingGuard implements ObserverInterface
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ActionFlag
     */
    private $actionFlag;

    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param CheckoutSession $checkoutSession Injected as a Proxy (etc/frontend/di.xml).
     * @param ActionFlag $actionFlag
     * @param ResponseInterface $response The shared response the front controller returns.
     * @param UrlInterface $url
     * @param MessageManager $messageManager
     */
    public function __construct(
        QuoteOnlyMode $quoteOnlyMode,
        CheckoutSession $checkoutSession,
        ActionFlag $actionFlag,
        ResponseInterface $response,
        UrlInterface $url,
        MessageManager $messageManager
    ) {
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->checkoutSession = $checkoutSession;
        $this->actionFlag = $actionFlag;
        $this->response = $response;
        $this->url = $url;
        $this->messageManager = $messageManager;
    }

    /**
     * Refuse multishipping checkout in quote-only mode.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->quoteOnlyMode->isActive()) {
            $this->refuse(
                (string) __('This store is quote-only. Request a quote for your items and we will send you a price.')
            );

            return;
        }

        try {
            $cart = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            return;
        }

        if ($this->quoteOnlyMode->cartHasQuoteOnlyProduct($cart)) {
            $this->refuse(
                (string) __(
                    'Your basket holds a product that is available on quote only. Remove it, or '
                    . 'request a quote for it, before checking out.'
                )
            );
        }
    }

    /**
     * Refuse with a message.
     *
     * @param string $message
     * @return void
     */
    private function refuse(string $message): void
    {
        $this->messageManager->addErrorMessage($message);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($this->url->getUrl('checkout/cart'));
        }
    }
}
