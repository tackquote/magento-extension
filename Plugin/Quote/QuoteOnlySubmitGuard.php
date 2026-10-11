<?php
/**
 * Quote-only mode — ORDER PLACEMENT, on every surface the storefront can reach.
 *
 * The cart cannot grow (Plugin\Quote\QuoteOnlyCartGuard), but a cart that predates the
 * switch, or one that already holds a product later marked quote-only, could still be turned
 * into an order. Plugin\Checkout\QuoteOnlyCheckoutGuard refuses the storefront checkout
 * PAGE; this refuses the conversion itself.
 *
 * WHY Magento\Quote\Model\QuoteManagement::submit(). Every storefront placement path ends
 * there (all line numbers Magento 2.4.8):
 *
 *   Luma checkout over REST   PaymentInformationManagement::savePaymentInformationAndPlaceOrder
 *                             -> CartManagementInterface::placeOrder (PaymentInformationManagement.php:156;
 *                             the guest variant GuestPaymentInformationManagement.php:142, and
 *                             GuestCartManagement::placeOrder delegates, GuestCartManagement.php:87)
 *   GraphQL placeOrder        Resolver\PlaceOrder -> Model\Cart\PlaceOrder ->
 *                             CartManagementInterface::placeOrder (Cart/PlaceOrder.php:61)
 *   placeOrder itself         QuoteManagement::placeOrderRun -> $this->submit($quote)
 *                             (QuoteManagement.php:476) — `$this` is the generated interceptor,
 *                             so the internal call is intercepted too
 *   frontend payment flows    e.g. PayPal Express review -> Onepage/Checkout -> submit()
 *
 * `submit()` is public and its class is not final, which is what interception requires
 * (developer.adobe.com/commerce/php/development/components/plugins, "Limitations").
 *
 * WHERE IT IS DECLARED. etc/frontend, etc/webapi_rest, etc/webapi_soap and etc/graphql
 * di.xml — never globally. Admin order creation (Sales > Orders > Create New Order) calls
 * submit() too, from the adminhtml area, which loads none of these files. In the API areas the
 * merchant's admin and integration tokens — TackQuote's OAuth-signed quote conversion
 * included — are exempt through Model\QuoteOnly\ApiVisitorContext.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlySubmitGuard
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     */
    public function __construct(QuoteOnlyMode $quoteOnlyMode)
    {
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * Signature mirrors QuoteManagement::submit(QuoteEntity $quote, $orderData = [])
     * (QuoteManagement.php:511).
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        if ($this->quoteOnlyMode->isActive()) {
            throw new LocalizedException(
                __('This store is quote-only. Request a quote for your items and we will send you a price.')
            );
        }

        if ($this->quoteOnlyMode->cartHasQuoteOnlyProduct($quote)) {
            throw new LocalizedException(
                __(
                    'Your basket holds a product that is available on quote only. Remove it, or '
                    . 'request a quote for it, before placing the order.'
                )
            );
        }
    }
}
