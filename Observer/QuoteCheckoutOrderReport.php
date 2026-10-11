<?php
/**
 * After an order is placed from an accepted-quote cart, tell TackQuote which order it became.
 *
 * `sales_model_service_quote_submit_success` is dispatched by
 * Magento\Quote\Model\QuoteManagement::submitQuote() with `order` and `quote` right after
 * OrderManagementInterface::place() saved the order (magento2 2.4-develop QuoteManagement.php:
 * place(), then the event, then the quote is saved), so the order has its entity id and increment
 * id here. It fires for every placement path that reaches submit(): Luma checkout over REST,
 * GraphQL placeOrder, and payment flows that call submit() directly.
 *
 * The work is in Model\QuoteCheckout\CompletionReporter, which never throws: an exception from
 * this observer would reach submitQuote()'s catch, roll back the quote's addresses and tell the
 * buyer the order failed, for an order that exists.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use TackQuote\Quotes\Model\QuoteCheckout\CompletionReporter;

class QuoteCheckoutOrderReport implements ObserverInterface
{
    /**
     * @var CompletionReporter
     */
    private $reporter;

    /**
     * @param CompletionReporter $reporter
     */
    public function __construct(CompletionReporter $reporter)
    {
        $this->reporter = $reporter;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        if ($order instanceof OrderInterface) {
            $this->reporter->report($order);
        }
    }
}
