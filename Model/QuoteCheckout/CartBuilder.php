<?php
/**
 * Puts an accepted TackQuote quote into the shopper's cart: every line, at its quoted quantity
 * and unit price, or nothing.
 *
 * THE WOOCOMMERCE BEHAVIOUR, MIRRORED (Tack_Quote_Checkout::build_cart(), plugin 1.11.0): the
 * cart of whoever opened the link (a signed-in customer's own cart, or a guest's session cart)
 * is EMPTIED, the quote's lines are added, and a line that cannot be bought (an unknown SKU, a
 * disabled or out-of-stock product, a quantity Magento would change) empties the cart again
 * and names the products. A buyer is never sent to pay for less than they accepted.
 *
 * REFUSED BEFORE THE CART IS TOUCHED:
 *   - a quote in another currency than the store's base AND current currency. The quoted price
 *     is set as the line's custom price, which Magento reads in the cart (quote) currency and
 *     converts to base through the store's rate (Magento\Quote\Model\Quote\Item\AbstractItem
 *     ::getCalculationPrice() / getBaseCalculationPrice()); an order paid in base currency would
 *     then not be the accepted amount;
 *   - a store whose catalog prices are entered INCLUDING tax (Stores > Configuration > Sales >
 *     Tax). A custom price is read in the catalog's tax basis and TackQuote's quoted prices are
 *     net, the same reason Model\Enforce\PriceEnforcementSwitch refuses account prices there.
 *   - a product that is not simple, virtual or downloadable (a configurable or bundle parent
 *     has no price of its own; quote the child SKU), the PR #6 rule
 *     (Model\Enforce\CustomPriceApplier::PRICED_TYPES).
 *
 * THE PRICE: `setCustomPrice()` + `setOriginalCustomPrice()`, as Magento's own admin order editor
 * does and as Model\Enforce\CustomPriceApplier does, never super mode (stock validation stays
 * on). The line's `additional_data` gets the quote marker (Model\QuoteCheckout\QuotedLine), which
 * the account-price enforcement treats as someone else's price and leaves alone.
 *
 * Sources: Adobe Commerce developer docs (Context7 /websites/developer_adobe_commerce) for
 * plugins and the cart service contracts; magento2 2.4-develop Quote.php (addProduct,
 * removeAllItems, beforeSave sets base/quote currency from the store) and Quote/etc/fieldset.xml.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteCheckout;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;

class CartBuilder
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @var QuotedLine
     */
    private $quotedLine;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param CartRepositoryInterface $cartRepository
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param QuotedCart $quotedCart
     * @param QuotedLine $quotedLine
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        CartRepositoryInterface $cartRepository,
        StoreManagerInterface $storeManager,
        Config $config,
        QuotedCart $quotedCart,
        QuotedLine $quotedLine
    ) {
        $this->productRepository = $productRepository;
        $this->cartRepository = $cartRepository;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->quotedCart = $quotedCart;
        $this->quotedLine = $quotedLine;
    }

    /**
     * Build and save the cart, or leave it as it was and say why.
     *
     * @param Quote $quote The shopper's cart (the checkout session's quote).
     * @param array $payload Validated by Model\QuoteCheckout\PayloadReader.
     * @param int $storeId
     * @return array{ok: bool, message?: Phrase}
     */
    public function build(Quote $quote, array $payload, int $storeId): array
    {
        $number = (string) $payload['quoteNumber'];
        $store = $this->storeManager->getStore($storeId);
        $base = strtoupper((string) $store->getBaseCurrencyCode());
        $current = strtoupper((string) $store->getCurrentCurrencyCode());
        if ($payload['currency'] !== $base || $payload['currency'] !== $current) {
            return $this->refuse(
                __(
                    'Quote %1 is priced in %2 but this store checks out in %3, so it cannot be checked out '
                    . 'here. Please contact the seller.',
                    $number,
                    $payload['currency'],
                    $payload['currency'] !== $current ? $current : $base
                )
            );
        }
        if ($this->config->catalogPricesIncludeTax($storeId)) {
            return $this->refuse(
                __(
                    'Quote %1 cannot be checked out here because this store enters its prices including '
                    . 'tax. Please contact the seller.',
                    $number
                )
            );
        }

        $products = [];
        $unavailable = [];
        foreach ($payload['lines'] as $index => $line) {
            $product = $this->product((string) $line['sku'], $storeId);
            if ($product === null) {
                $unavailable[] = (string) $line['name'];
                continue;
            }
            $products[$index] = $product;
        }
        if ($unavailable !== []) {
            return $this->unavailable($number, $unavailable);
        }

        $quote->removeAllItems();
        $failed = [];
        $count = count($payload['lines']);
        $this->quotedCart->startBuilding();
        try {
            foreach ($payload['lines'] as $index => $line) {
                if (!$this->addLine($quote, $products[$index], $line, $payload, $count)) {
                    $failed[] = (string) $line['name'];
                }
            }
        } finally {
            $this->quotedCart->stopBuilding();
        }

        if ($failed !== []) {
            $quote->removeAllItems();
            $this->save($quote);

            return $this->unavailable($number, $failed);
        }

        if (!$quote->getCustomerId() && (string) $quote->getCustomerEmail() === ''
            && (string) $payload['buyerEmail'] !== ''
        ) {
            // A guest's checkout starts with the quote's email filled in.
            $quote->setCustomerEmail((string) $payload['buyerEmail']);
        }

        $this->save($quote);

        if (!$this->quotedCart->isIntact($quote)) {
            // Something changed a line between the add and the save (another extension's
            // price or quantity rule). Never send the buyer to pay for a different cart.
            $quote->removeAllItems();
            $this->save($quote);

            return $this->refuse(
                __(
                    'Quote %1 could not be put into the cart at its accepted prices and quantities. '
                    . 'Please contact the seller.',
                    $number
                )
            );
        }

        return ['ok' => true];
    }

    /**
     * Add one line at its quoted quantity and price.
     *
     * @param Quote $quote
     * @param Product $product
     * @param array $line
     * @param array $payload
     * @param int $count
     * @return bool
     */
    private function addLine(Quote $quote, Product $product, array $line, array $payload, int $count): bool
    {
        try {
            $item = $quote->addProduct($product, (int) $line['quantity']);
        } catch (\Exception $e) {
            return false;
        }
        if (!$item instanceof Item || $item->getParentItem() || $item->getHasError()
            || abs((float) $item->getQty() - (int) $line['quantity']) > 0.0001
        ) {
            // A string is Magento's own refusal; a changed quantity would charge for another amount.
            if ($item instanceof Item) {
                $quote->deleteItem($item);
            }

            return false;
        }

        $price = (float) $line['unitPrice'];
        $stamped = $this->quotedLine->stamp(
            $item,
            [
                'ref' => (string) $payload['quoteRef'],
                'number' => (string) $payload['quoteNumber'],
                'price' => $price,
                'qty' => (int) $line['quantity'],
                'lines' => $count,
                'currency' => (string) $payload['currency'],
            ]
        );
        if (!$stamped) {
            return false;
        }
        $item->setCustomPrice($price);
        $item->setOriginalCustomPrice($price);

        return true;
    }

    /**
     * The product for a quoted SKU in this store, or null when it cannot carry a quoted price.
     *
     * @param string $sku
     * @param int $storeId
     * @return Product|null
     */
    private function product(string $sku, int $storeId): ?Product
    {
        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        if (!$product instanceof Product
            || !in_array((string) $product->getTypeId(), CustomPriceApplier::PRICED_TYPES, true)
        ) {
            return null;
        }

        return $product;
    }

    /**
     * Collect totals and save, the way Magento\Checkout\Model\Cart::save() does.
     *
     * @param Quote $quote
     * @return void
     */
    private function save(Quote $quote): void
    {
        $quote->getBillingAddress();
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
    }

    /**
     * Refuse a quote whose products cannot be bought here.
     *
     * @param string $number
     * @param string[] $names
     * @return array{ok: bool, message: Phrase}
     */
    private function unavailable(string $number, array $names): array
    {
        return $this->refuse(
            __(
                'Quote %1 could not be checked out because these products are not available to buy in this '
                . 'store right now: %2. Please contact the seller.',
                $number,
                implode(', ', $names)
            )
        );
    }

    /**
     * Build a refused result.
     *
     * @param Phrase $message
     * @return array{ok: bool, message: Phrase}
     */
    private function refuse(Phrase $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
