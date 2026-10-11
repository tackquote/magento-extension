<?php
/**
 * Before Magento collects a cart's totals, put TackQuote's account prices on its lines.
 *
 * A `before` plugin on Magento\Quote\Model\Quote::collectTotals() (`@api`, Quote.php:34;
 * collectTotals() at Quote.php:2045). Every path that prices a cart ends there: the cart page
 * and mini cart, REST/SOAP `carts/mine/totals`, GraphQL cart prices, the checkout's
 * `shipping-information` and `payment-information` calls (placeOrder() collects totals itself
 * when no payment method is passed, QuoteManagement.php:483), and a reorder, which rebuilds the
 * cart line by line and then collects. The work is in Model\Enforce\CustomPriceApplier.
 *
 * Nothing is done when the quote's totals are already collected (collectTotals() returns at
 * once then, Quote.php:2047), so a price is never changed underneath totals that will not be
 * recomputed.
 *
 * DECLARED in etc/frontend/di.xml and the three Web API areas only, never in etc/di.xml: the
 * merchant's admin order editor drives the same method and must keep the prices the merchant
 * types (see the header of etc/frontend/di.xml).
 *
 * FAILURE never breaks the cart: an unexpected exception is logged (class name only) and the
 * totals are collected with whatever prices the lines already hold.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;

class AccountPriceTotalsPlugin
{
    /**
     * @var CustomPriceApplier
     */
    private $applier;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CustomPriceApplier $applier
     * @param LoggerInterface $logger
     */
    public function __construct(CustomPriceApplier $applier, LoggerInterface $logger)
    {
        $this->applier = $applier;
        $this->logger = $logger;
    }

    /**
     * @param Quote $subject
     * @return void
     */
    public function beforeCollectTotals(Quote $subject): void
    {
        if ($subject->getTotalsCollectedFlag()) {
            return;
        }

        try {
            $this->applier->apply($subject);
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf('TackQuote account prices were not applied to a cart (%s).', get_class($e))
            );
        }
    }
}
