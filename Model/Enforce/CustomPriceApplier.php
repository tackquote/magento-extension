<?php
/**
 * Puts TackQuote's account price on each cart line it applies to, and takes it off again
 * when it no longer applies.
 *
 * THE MECHANISM — a quote item's custom price. Magento prices a line from
 * `getCalculationPrice()`, which returns the custom price when one is set and the product's
 * converted price otherwise, and derives the base-currency price from it through the store's
 * rate (Magento\Quote\Model\Quote\Item\AbstractItem::getCalculationPrice() /
 * getBaseCalculationPrice(), magento2 2.4-develop AbstractItem.php:334-384; setCustomPrice()
 * resets both cached values, :437). So the custom price is in the CART's currency, which is
 * why only a price confirmed to be in that currency is ever set (Model\Enforce\PriceResolver).
 * The order line takes `calculation_price` as its `price` and also carries the quote item's
 * `additional_data` (Magento/Quote/etc/fieldset.xml, quote_convert_item: additional_data
 * :225, calculation_price -> price :228, base_calculation_price -> base_price :231). Both
 * `custom_price` and `original_custom_price` are set, as Magento's own admin order editor does.
 *
 * NOT `setIsSuperMode(true)`. The usual recipe pairs a custom price with
 * `$item->getProduct()->setIsSuperMode(true)`, but core reads "super mode" on the QUOTE, where
 * it switches inventory validation off entirely (CatalogInventory QuantityValidator.php:129,
 * `if ($quoteItem->getQuote()->getIsSuperMode()) return;`) and lets the subtotal collector price
 * a product that is not visible in the catalog (Quote/Model/Quote/Address/Total/Subtotal.php:94).
 * A storefront cart must keep both checks, and the custom price needs neither.
 *
 * WHEN — before every totals collection (Plugin\Quote\AccountPriceTotalsPlugin, a `before`
 * plugin on Quote::collectTotals(), Quote.php:2045), so it holds through the cart page, the
 * mini cart, REST/GraphQL cart totals, checkout's `payment-information` call and a reorder
 * (which rebuilds the cart through Quote::addProduct() and then collects totals). At order
 * submit it is re-run with a FRESH TackQuote answer (Plugin\Quote\AccountPriceSubmitPlugin).
 *
 * WHICH LINES — simple, virtual and downloadable products only, never a child line or a line
 * with children. A configurable, bundle or grouped product's page shows no account price
 * (Controller\B2b\Display SELECTION_PRICED_TYPES), so its cart line is not charged one either;
 * grouped products enter the cart as their simple children and are priced as such. A line whose
 * product is quote-only by its own flag is skipped, and nothing at all is charged where
 * quote-only mode hides prices from this customer: a hidden price is never charged. A
 * fractional quantity is skipped (the server prices whole quantities only).
 *
 * WHOSE PRICE IT IS — a line this module priced carries a marker in the quote item's
 * `additional_data` (JSON, key MARKER, the price it set). Only a line whose custom price still
 * equals its marker is treated as ours: that is the only custom price ever cleared or replaced.
 * A custom price set by anything else (another extension) is left alone and the line is not
 * repriced; `additional_data` that is not a JSON object belongs to someone else and is never
 * overwritten, so that line keeps its own price too.
 *
 * AN ACCEPTED QUOTE'S LINE is never ours (lane MG-QUOTE-CHECKOUT-LINK): a line whose
 * `additional_data` carries the quote marker (Model\QuoteCheckout\QuotedLine, `tackquote_quote_ref`
 * / `tackquote_quoted_price`) holds the price the seller and the buyer agreed on. It is not asked
 * about, never set, never cleared, even where account prices are off or hidden.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class CustomPriceApplier
{
    /** `additional_data` key holding the account price this module set on the line. */
    public const MARKER = 'tackquote_account_price';

    /**
     * Quote data key (in memory only; the quote table has no such column): true when this
     * buyer's account prices could not be confirmed and the store's prices stand.
     * Observer\AccountPriceOrderComment writes it onto the order.
     */
    public const FALLBACK_FLAG = 'tackquote_account_price_fallback';

    /** Product types that are priced as themselves. See "WHICH LINES". */
    public const PRICED_TYPES = ['simple', 'virtual', 'downloadable'];

    /** Two prices closer than this are the same price (DECIMAL(12,4) columns). */
    private const PRICE_EPSILON = 0.00005;

    /**
     * @var Eligibility
     */
    private $eligibility;

    /**
     * @var PriceEnforcementSwitch
     */
    private $switch;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var PriceResolver
     */
    private $resolver;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Eligibility $eligibility
     * @param PriceEnforcementSwitch $switch
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param PriceResolver $resolver
     * @param Json $json
     */
    public function __construct(
        Eligibility $eligibility,
        PriceEnforcementSwitch $switch,
        QuoteOnlyMode $quoteOnlyMode,
        PriceResolver $resolver,
        Json $json
    ) {
        $this->eligibility = $eligibility;
        $this->switch = $switch;
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->resolver = $resolver;
        $this->json = $json;
    }

    /**
     * Bring every line's custom price in line with what TackQuote charges this buyer.
     *
     * @param Quote $quote
     * @param bool $fresh Ask TackQuote again, ignoring every cache (the order submit).
     * @return bool True when any line's price changed (totals must be collected again).
     */
    public function apply(Quote $quote, bool $fresh = false): bool
    {
        $items = (array) $quote->getAllVisibleItems();
        $prices = [];
        $fallback = false;

        $storeId = (int) $quote->getStoreId();
        // The store-config switch first: with it off nothing reads the session or the customer.
        $buyer = $this->switch->isOn($storeId) ? $this->eligibility->buyer($quote) : null;
        $charging = $buyer !== null && !$this->quoteOnlyMode->shouldHidePrices($storeId);

        $lines = [];
        if ($charging) {
            foreach ($items as $item) {
                $line = $item instanceof Item ? $this->line($item, $storeId) : null;
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                $resolution = $this->resolver->resolve($buyer, $lines, $fresh);
                $prices = $resolution['prices'];
                $fallback = $resolution['fallback'];
            }
        }
        $quote->setData(self::FALLBACK_FLAG, $fallback);

        $changed = false;
        foreach ($items as $item) {
            if (!$item instanceof Item) {
                continue;
            }
            $line = $charging ? $this->line($item, $storeId) : null;
            $desired = $line !== null
                ? ($prices[AnswerMapper::lineKey($line['sku'], $line['quantity'])] ?? null)
                : null;
            $changed = $this->settle($item, $desired) || $changed;
        }

        return $changed;
    }

    /**
     * The line TackQuote is asked to price for this item, or null when it is not priced.
     *
     * @param Item $item
     * @param int $storeId
     * @return array{sku: string, quantity: int}|null
     */
    private function line(Item $item, int $storeId): ?array
    {
        if ($item->getParentItem() || $item->getHasChildren()
            // The type stored on the line (Quote\Item::setProduct() writes the product's own type),
            // not getProductType(), which answers "grouped" for a grouped product's simple child.
            || !in_array((string) $item->getData('product_type'), self::PRICED_TYPES, true)
        ) {
            return null;
        }
        if ($this->isQuoted($this->additionalData($item))) {
            // An accepted quote's price, not an account price: see "AN ACCEPTED QUOTE'S LINE".
            return null;
        }
        $sku = trim((string) $item->getSku());
        $qty = (float) $item->getQty();
        if ($sku === '' || mb_strlen($sku) > 128 || $qty < 1 || floor($qty) !== $qty) {
            return null;
        }
        $productId = (int) $item->getProductId();
        if ($productId > 0 && $this->quoteOnlyMode->isProductIdQuoteOnly($productId, $storeId)) {
            return null;
        }

        return ['sku' => $sku, 'quantity' => (int) $qty];
    }

    /**
     * Set, keep or clear this line's TackQuote price.
     *
     * @param Item $item
     * @param float|null $desired The account price to charge, or null for "Magento's price".
     * @return bool Whether the line's price changed.
     */
    private function settle(Item $item, ?float $desired): bool
    {
        $data = $this->additionalData($item);
        if ($this->isQuoted($data)) {
            return false;
        }
        $current = $item->getCustomPrice();
        $marker = is_array($data) && isset($data[self::MARKER]) && is_numeric($data[self::MARKER])
            ? (float) $data[self::MARKER]
            : null;
        $ours = $marker !== null && $current !== null && is_numeric($current)
            && abs((float) $current - $marker) < self::PRICE_EPSILON;

        if ($desired === null) {
            if (!$ours) {
                return false;
            }
            unset($data[self::MARKER]);
            $item->setCustomPrice(null);
            $item->setOriginalCustomPrice(null);
            $item->setAdditionalData($data === [] ? null : (string) $this->json->serialize($data));

            return true;
        }

        if ($data === null) {
            // Someone else's additional_data: never overwritten, so never priced.
            return false;
        }
        if ($current !== null && !$ours) {
            // Someone else's custom price.
            return false;
        }
        if ($ours && abs($marker - $desired) < self::PRICE_EPSILON) {
            return false;
        }

        $data[self::MARKER] = $desired;
        $item->setCustomPrice($desired);
        $item->setOriginalCustomPrice($desired);
        $item->setAdditionalData((string) $this->json->serialize($data));

        return true;
    }

    /**
     * Whether decoded `additional_data` carries an accepted quote's marker.
     *
     * @param array|null $data
     * @return bool
     */
    private function isQuoted(?array $data): bool
    {
        return is_array($data)
            && (array_key_exists(QuotedLine::REF, $data) || array_key_exists(QuotedLine::PRICE, $data));
    }

    /**
     * The line's `additional_data` as an array we may write to: [] when empty, the decoded
     * object when it is a JSON object, and null when it holds anything else.
     *
     * @param Item $item
     * @return array|null
     */
    private function additionalData(Item $item): ?array
    {
        $raw = $item->getAdditionalData();
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            return null;
        }
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }
}
