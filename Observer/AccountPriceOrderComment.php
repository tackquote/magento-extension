<?php
/**
 * Records on the order that TackQuote's account prices could not be confirmed, so the store's
 * prices were charged.
 *
 * `sales_model_service_quote_submit_before` is dispatched by QuoteManagement::submitQuote() with
 * the order and the quote, after the order is built and before it is placed and saved
 * (magento2 2.4-develop QuoteManagement.php:674-680), so a comment added here is saved with the
 * order. The quote carries the flag in memory only
 * (Model\Enforce\CustomPriceApplier::FALLBACK_FLAG), set by the fresh resolution that
 * Plugin\Quote\AccountPriceSubmitPlugin runs immediately before.
 *
 * The comment is for the merchant: not visible on the storefront and not e-mailed
 * (Order::addCommentToStatusHistory($comment, $status = false, $isVisibleOnFront = false)).
 * The order is typed loosely on purpose: the module does not otherwise depend on Magento_Sales.
 *
 * Registered in the GLOBAL etc/events.xml: the flag is only ever set by the storefront and Web
 * API areas, so the observer has nothing to do anywhere else.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;

class AccountPriceOrderComment implements ObserverInterface
{
    /**
     * Note on the order when account prices could not be confirmed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof DataObject || $quote->getData(CustomPriceApplier::FALLBACK_FLAG) !== true) {
            return;
        }
        if (!is_object($order) || !method_exists($order, 'addCommentToStatusHistory')) {
            return;
        }

        $order->addCommentToStatusHistory(
            (string) __('TackQuote account price could not be confirmed; store price charged.')
        );
    }
}
