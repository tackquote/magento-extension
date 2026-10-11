<?php
/**
 * Formats the B2B facts for the product page: money in the store's own format, the
 * account price on the store's tax display basis, and every sentence translated here.
 *
 * The browser receives finished TEXT only and inserts it with textContent, never as HTML,
 * so nothing TackQuote or a merchant typed (a buyer-group name) can become markup.
 *
 * MONEY — TackQuote prices are NET unit prices in the tenant's currency (the WooCommerce
 * plugin applies them as "Net unit price resolved by TackQuote", class-tack-wholesale-
 * pricing.php). ResponseMapper has already refused any answer whose currency is not the
 * page's, so formatting below never converts: PriceCurrencyInterface::format() formats
 * an amount in a given currency without converting it, whereas convertAndFormat() would
 * run it through the store's exchange rate a second time.
 * UNVERIFIED on a store: that format() does not convert (read from the interface's
 * contract, format vs convertAndFormat; confirm on the acceptance store with a non-base
 * display currency).
 *
 * TAX — the account price goes through Magento\Catalog\Helper\Data::getTaxPrice() with
 * `$priceIncludesTax = false` (TackQuote's price is net) and `$includingTax = null` (the
 * store's catalog display setting decides), so it is shown on the same basis as the
 * product's own price beside it. The struck-through store price is the product's
 * `regular_price` amount as Magento's price box shows it.
 * UNVERIFIED on a store: both values under "display prices including tax" and "display
 * both"; see the PR's acceptance steps.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\B2bDisplay;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;

class Presenter
{
    /** What the browser receives when there is nothing to show. */
    public const EMPTY_PAYLOAD = [
        'accountPrice' => null,
        'quantityBreaks' => null,
        'orderLimit' => null,
        'buyerGroup' => null,
        'cartNote' => null,
    ];

    private const PRICE_EPSILON = 0.00001;

    /**
     * @var CatalogHelper
     */
    private $catalogHelper;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @param CatalogHelper $catalogHelper
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(CatalogHelper $catalogHelper, PriceCurrencyInterface $priceCurrency)
    {
        $this->catalogHelper = $catalogHelper;
        $this->priceCurrency = $priceCurrency;
    }

    /**
     * DataProvider facts -> the JSON the product page renders.
     *
     * @param array $facts DataProvider::get() result.
     * @param Product $product
     * @param StoreInterface $store
     * @param string $currency The page currency the facts were checked against.
     * @return array
     */
    public function present(array $facts, Product $product, StoreInterface $store, string $currency): array
    {
        $out = self::EMPTY_PAYLOAD;

        if (is_array($facts['accountPrice'] ?? null)) {
            $unit = $this->displayPrice($product, (float) $facts['accountPrice']['unitPrice'], $store);
            $list = $this->storePrice($product);
            $out['accountPrice'] = [
                'label' => (string) __('Your account price'),
                'price' => $this->format($unit, $store, $currency),
                // Struck through only when TackQuote is genuinely lower: a "was" price
                // below the "now" price reads as a price rise.
                'storePrice' => ($list !== null && $list - $unit > self::PRICE_EPSILON)
                    ? $this->format($list, $store, $currency)
                    : null,
                'storePriceLabel' => (string) __('Store price'),
            ];
        }

        if (is_array($facts['quantityBreaks'] ?? null)) {
            $rows = [];
            foreach ($facts['quantityBreaks']['rows'] as $row) {
                $rows[] = [
                    'quantity' => (string) __('%1+', (int) $row['minQty']),
                    'price' => $this->format(
                        $this->displayPrice($product, (float) $row['unitPrice'], $store),
                        $store,
                        $currency
                    ),
                ];
            }
            $out['quantityBreaks'] = [
                // A negotiated ladder shown without saying so reads as a public price list.
                'caption' => !empty($facts['quantityBreaks']['accountSpecific'])
                    ? (string) __('Volume pricing for your account')
                    : (string) __('Volume pricing'),
                'quantityHeading' => (string) __('Quantity'),
                'priceHeading' => (string) __('Unit price'),
                'rows' => $rows,
            ];
        }

        // Display only: until the cart charges TackQuote prices, a shopper who sees one must
        // be told plainly which price checkout uses. Shown only beside a TackQuote price.
        if ($out['accountPrice'] !== null || $out['quantityBreaks'] !== null) {
            $out['cartNote'] = [
                'text' => (string) __(
                    'Your account prices are shown here; the cart and checkout use the store\'s prices for now.'
                ),
            ];
        }

        if (is_array($facts['orderLimit'] ?? null)) {
            $out['orderLimit'] = ['text' => $this->limitSentence($facts['orderLimit'])];
        }

        if (is_array($facts['buyerGroup'] ?? null)) {
            $out['buyerGroup'] = [
                'label' => (string) __('Buyer group'),
                'name' => (string) $facts['buyerGroup']['name'],
            ];
        }

        return $out;
    }

    /**
     * The order-limit sentence, the same three phrasings as the WooCommerce plugin.
     *
     * @param array{min: int|null, max: int|null} $limit
     * @return string
     */
    public function limitSentence(array $limit): string
    {
        $min = $limit['min'] ?? null;
        $max = $limit['max'] ?? null;
        if ($min !== null && $max !== null) {
            return (string) __('Order between %1 and %2 units of this product.', (int) $min, (int) $max);
        }
        if ($min !== null) {
            return (string) __('Minimum order quantity: %1 units.', (int) $min);
        }

        return (string) __('Maximum order quantity: %1 units.', (int) $max);
    }

    /**
     * A net TackQuote unit price on the store's catalog tax display basis.
     *
     * @param Product $product
     * @param float $netPrice
     * @param StoreInterface $store
     * @return float
     */
    private function displayPrice(Product $product, float $netPrice, StoreInterface $store): float
    {
        return (float) $this->catalogHelper->getTaxPrice(
            $product,
            $netPrice,
            null,
            null,
            null,
            null,
            $store,
            false
        );
    }

    /**
     * The product's own regular price as its price box shows it, or null.
     *
     * @param Product $product
     * @return float|null
     */
    private function storePrice(Product $product): ?float
    {
        try {
            $value = $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
        } catch (\Exception $e) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Money as text in the page currency, without conversion.
     *
     * @param float $amount
     * @param StoreInterface $store
     * @param string $currency
     * @return string
     */
    private function format(float $amount, StoreInterface $store, string $currency): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $store,
            $currency
        );
    }
}
