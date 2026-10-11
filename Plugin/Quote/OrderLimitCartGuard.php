<?php
/**
 * Refuses adding a product when the cart's total for its SKU would break the TackQuote order
 * limit the product page states.
 *
 * A `before` plugin on Magento\Quote\Model\Quote::addProduct() (Quote.php:1640), the method every
 * add path funnels into on the storefront (product page, wishlist, reorder through
 * Checkout\Model\Cart) and over the Web API (CartItemPersister for a new line, GraphQL
 * AddProductsToCart): the same interception point and the same area declarations as the
 * quote-only guard (Plugin\Quote\QuoteOnlyCartGuard), ordered after it (sortOrder 20 > 10).
 * A refusal is a LocalizedException, which the storefront and the APIs show as the message.
 *
 * The quantity added is read the way addProduct() reads it (Quote.php:1645-1650): no request is
 * 1, a number is the quantity, a DataObject carries `qty`. A missing or non-positive `qty` is
 * left to Magento (which applies the product's own minimum) and counts as 1 here.
 *
 * Only products priced as themselves are checked here (simple, virtual, downloadable): for a
 * configurable or bundle the SKU that lands in the cart depends on the selection, so their lines
 * are checked by the cart-update guards and at order submit, where the line's SKU is known.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;

class OrderLimitCartGuard
{
    /**
     * @var OrderLimitChecker
     */
    private $checker;

    /**
     * @param OrderLimitChecker $checker
     */
    public function __construct(OrderLimitChecker $checker)
    {
        $this->checker = $checker;
    }

    /**
     * Signature mirrors Quote::addProduct(Product $product, $request = null, $processMode = ...).
     *
     * @param Quote $subject
     * @param Product $product
     * @param mixed $request
     * @param string|null $processMode
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddProduct(
        Quote $subject,
        Product $product,
        $request = null,
        $processMode = null
    ): void {
        if (!in_array((string) $product->getTypeId(), CustomPriceApplier::PRICED_TYPES, true)) {
            return;
        }

        $this->checker->assertAdd(
            $subject,
            (string) $product->getSku(),
            self::requestedQty($request),
            (string) $product->getName()
        );
    }

    /**
     * The quantity an addProduct() request asks for.
     *
     * @param mixed $request
     * @return float
     */
    public static function requestedQty($request): float
    {
        if ($request === null) {
            return 1.0;
        }
        if (is_numeric($request)) {
            $qty = (float) $request;
        } elseif ($request instanceof DataObject) {
            $qty = is_numeric($request->getData('qty')) ? (float) $request->getData('qty') : 1.0;
        } else {
            return 1.0;
        }

        return $qty > 0 ? $qty : 1.0;
    }
}
