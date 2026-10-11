<?php
/**
 * An accepted quote is checked out on its own: nothing else is added to a cart that holds one.
 *
 * The WooCommerce plugin's mixing refusal (Tack_Quote_Checkout::refuse_mixing(), and its Store
 * API twin). A `before` plugin on Magento\Quote\Model\Quote::addProduct() (`@api`), the method
 * every add path reaches (Plugin\Quote\QuoteOnlyCartGuard explains why there and not a
 * controller), declared in the frontend, REST, SOAP and GraphQL areas and ordered BEFORE the
 * quote-only guard (5 < 10), so a shopper holding a quote reads this explanation rather than
 * the store-wide one.
 *
 * Model\QuoteCheckout\CartBuilder's own adds pass: it sets QuotedCart::isBuilding() while it puts
 * the quote's lines in.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;

class QuoteCheckoutCartGuard
{
    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @param QuotedCart $quotedCart
     */
    public function __construct(QuotedCart $quotedCart)
    {
        $this->quotedCart = $quotedCart;
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
        if ($this->quotedCart->isBuilding() || !$this->quotedCart->hasQuotedLines($subject)) {
            return;
        }

        throw new LocalizedException(
            __(
                'Your cart holds an accepted quote, which is checked out on its own. Complete or remove '
                . 'it before adding other products.'
            )
        );
    }
}
