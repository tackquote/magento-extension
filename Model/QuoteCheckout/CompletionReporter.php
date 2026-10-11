<?php
/**
 * Tells TackQuote which order an accepted-quote cart became.
 *
 * `POST /integrations/magento/quote-checkout/complete` (Model\QuoteCheckout\CheckoutApiClient)
 * with the checkout reference the cart lines carry, the order's entity id and increment id, its
 * state and its payment method. TackQuote moves the quote's claim to "ordered" with this order
 * and, when the state is paid-like (`processing`, `complete`) and the method is not
 * `tackquote_net_terms`, settles the quote.
 *
 * IDEMPOTENT ON THE SERVER: the same (reference, order) answers the same body however often it is
 * sent, so a retry or a second observer run is harmless; a DIFFERENT order through the same
 * reference is refused (409) and settles nothing.
 *
 * NEVER FAILS AN ORDER: the order already exists when this runs. A lost report (TackQuote
 * unreachable, a 3-second timeout) is logged with the status only; TackQuote's order pull reads
 * the same reference from the order lines' `additional_data` and links the quote then
 * (tack `linkMagentoQuoteCheckoutOrder`).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class CompletionReporter
{
    /** Magento order states (Magento\Sales\Model\Order::STATE_*), the only ones reported. */
    public const STATES = [
        'new',
        'pending_payment',
        'payment_review',
        'processing',
        'complete',
        'closed',
        'canceled',
        'holded',
    ];

    /**
     * @var CheckoutApiClient
     */
    private $client;

    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CheckoutApiClient $client
     * @param QuotedLine $quotedLine
     * @param LoggerInterface $logger
     */
    public function __construct(CheckoutApiClient $client, QuotedLine $quotedLine, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->quotedLine = $quotedLine;
        $this->logger = $logger;
    }

    /**
     * The checkout reference an order's lines carry: exactly one well-formed one, or null.
     *
     * @param OrderInterface $order
     * @return string|null
     */
    public function refOf(OrderInterface $order): ?string
    {
        $refs = [];
        foreach ((array) $order->getItems() as $item) {
            if (!is_object($item) || !method_exists($item, 'getAdditionalData')) {
                continue;
            }
            $marker = $this->quotedLine->fromData($this->quotedLine->decode($item->getAdditionalData()));
            if ($marker !== null) {
                $refs[$marker['ref']] = true;
            }
        }

        return count($refs) === 1 ? (string) array_key_first($refs) : null;
    }

    /**
     * The body `complete` sends for an order, or null when the order is not a quoted one.
     *
     * @param OrderInterface $order
     * @return array{quoteRef: string, orderId: string, incrementId: string, state?: string,
     *               paymentMethod?: string}|null
     */
    public function body(OrderInterface $order): ?array
    {
        $ref = $this->refOf($order);
        $orderId = (string) (int) $order->getEntityId();
        $incrementId = (string) $order->getIncrementId();
        if ($ref === null || $orderId === '0' || preg_match('/^[A-Za-z0-9_-]{1,50}$/', $incrementId) !== 1) {
            return null;
        }
        $body = ['quoteRef' => $ref, 'orderId' => $orderId, 'incrementId' => $incrementId];
        $state = (string) $order->getState();
        if (in_array($state, self::STATES, true)) {
            $body['state'] = $state;
        }
        $payment = $order->getPayment();
        $method = $payment ? (string) $payment->getMethod() : '';
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $method) === 1) {
            $body['paymentMethod'] = $method;
        }

        return $body;
    }

    /**
     * Report the order. Never throws.
     *
     * @param OrderInterface $order
     * @return bool Whether TackQuote recorded it.
     */
    public function report(OrderInterface $order): bool
    {
        try {
            $body = $this->body($order);
            if ($body === null) {
                return false;
            }
            $storeId = $order->getStoreId() !== null ? (int) $order->getStoreId() : null;
            $answer = $this->client->complete($body, $storeId);
            if (!$answer['ok']) {
                $this->logger->warning(sprintf(
                    'TackQuote was not told which order an accepted quote became (HTTP %d); '
                    . 'the order pull will link it.',
                    (int) $answer['status']
                ));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('TackQuote quote checkout report failed (%s).', get_class($e)));

            return false;
        }
    }
}
