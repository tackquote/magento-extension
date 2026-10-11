<?php
/**
 * Quote-only (B2B catalog) mode — THE REFUSAL.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXTENSION POINT AND NOT A CONTROLLER PLUGIN
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Hiding the Add to Cart button is not a policy — it is a CSS class, and anyone can
 * `curl -d 'product=42&qty=1' https://store/checkout/cart/add/`. So the refusal happens in
 * PHP, inside the model every add path funnels through.
 *
 * The guard is on **Magento\Quote\Model\Quote::addProduct()**
 * (vendor/magento/module-quote/Model/Quote.php:1630), which is marked `@api` at
 * Quote.php:34 — a stability guarantee Magento gives for exactly this purpose. It is
 * deliberately NOT a plugin on Magento\Checkout\Controller\Cart\Add:
 *
 *   - A controller plugin guards ONE route. `checkout/cart/add` is not the only way a
 *     product reaches a cart: related-product checkboxes, wishlist "Add to Cart",
 *     `checkout/cart/addgroup`, and any third-party module that calls the cart directly all
 *     bypass it. Every one of those ends at Quote::addProduct().
 *   - Magento\Checkout\Model\Cart::addProduct() (module-checkout/Model/Cart.php:380) was the
 *     other candidate and is rejected for a stated reason: its own class docblock carries
 *     `@deprecated 100.1.0 Use \Magento\Quote\Model\Quote instead`. It is also merely a
 *     caller — Cart.php:392 is literally `$result = $this->getQuote()->addProduct($product,
 *     $request);` — so guarding Quote::addProduct() catches it and everything else at once.
 *
 * A `before` plugin that throws aborts the intercepted call, so core's cart code never runs.
 * `LocalizedException` is chosen over a generic exception because the storefront already
 * knows how to present it: Magento\Checkout\Controller\Cart\Add::execute() catches
 * `\Magento\Framework\Exception\LocalizedException` at Add.php:170 and renders the message
 * to the shopper, rather than producing a 500 or a blank page.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * HOW ADMIN AND INTEGRATIONS STAY EXEMPT
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Quote::addProduct() is also how the ADMIN builds an order by hand (Sales > Orders > Create
 * New Order) and how the Web API adds items. Neither may be refused: quote-only mode is a
 * STOREFRONT policy, and blocking the admin would stop the merchant taking a phone order and
 * stop TackQuote converting the very quotes the mode exists to collect.
 *
 * The exemption is STRUCTURAL, not a runtime `if`: this plugin is declared only in
 * `etc/frontend/di.xml`, so Magento generates the interceptor for the frontend area alone.
 * Area-scoped di.xml is Magento's own mechanism for this — core does the same thing with
 * Magento\Customer\Model\App\Action\ContextPlugin, declared in
 * vendor/magento/module-customer/etc/frontend/di.xml:23-26. A runtime area check could be
 * got wrong or removed; a file that is never loaded in adminhtml cannot be.
 *
 * THE WEB API AND GRAPHQL. This plugin is now ALSO declared in
 * etc/webapi_rest/di.xml, etc/webapi_soap/di.xml and etc/graphql/di.xml, because every cart
 * path there funnels into the same method:
 *
 *   REST/SOAP  POST /V1/carts/mine/items, POST /V1/guest-carts/{id}/items
 *              -> CartItemRepositoryInterface::save (GuestCartItemRepository::save delegates
 *                 to it, GuestCartItemRepository.php:61-66) -> CartItemPersister::save, which
 *                 calls $quote->addProduct() for a new line (CartItemPersister.php:98).
 *   GraphQL    addProductsToCart -> Magento\Quote\Model\Cart\AddProductsToCart, which calls
 *              $cart->addProduct() (AddProductsToCart.php:163); the older per-type mutations
 *              reach the same method.
 *              (All line numbers Magento 2.4.8.)
 *
 * What stops it from refusing the merchant's own integrations there is the caller's TYPE,
 * read through Magento\Authorization\Model\UserContextInterface by
 * Model\QuoteOnly\ApiVisitorContext: an admin or integration token — and TackQuote's
 * OAuth-signed quote conversion, which is typed as an integration — is exempt; a customer
 * token, a customer session, or an anonymous caller is not. The admin area itself still
 * loads none of these files.
 *
 * PER-PRODUCT. A product whose own `tackquote_quote_only` attribute is Yes is refused too,
 * whether or not the store-wide mode is on (QuoteOnlyMode::appliesToProduct()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyCartGuard
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
     * Refuse to put anything new into the cart.
     *
     * Signature mirrors Magento\Quote\Model\Quote::addProduct() (Quote.php:1630-1634). A
     * `before` plugin returning void leaves the arguments untouched; throwing aborts the
     * call before core's code runs.
     *
     * @param Quote $subject
     * @param Product $product
     * @param mixed $request
     * @param string $processMode
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
        if ($this->quoteOnlyMode->isActive()) {
            $this->refuseStore();
        }

        if ($this->quoteOnlyMode->isProductQuoteOnly($product)) {
            throw new LocalizedException(
                __(
                    '%1 is available on quote only. Add it to your quote list and request a price.',
                    (string) $product->getName()
                )
            );
        }
    }

    /**
     * The store-wide refusal.
     *
     * @return void
     * @throws LocalizedException
     */
    private function refuseStore(): void
    {
        throw new LocalizedException(
            __(
                'This store is quote-only. Add the products you need to your quote list and '
                . 'request a price — checkout is not available here.'
            )
        );
    }
}
