<?php
/**
 * Refuses a cart change or an order that breaks a TackQuote order limit.
 *
 * WHICH LIMITS — the quantity bound the product page's notice states, read by the display's own
 * mapper (Model\B2bDisplay\ResponseMapper::orderLimit(): the first `product_qty` /
 * `per_product_qty` rule with a bound), and, at order submit only, every `order_total` rule in
 * the cart's currency (or with no currency), which is money and is compared with the order's
 * grand total, the figure TackQuote's own evaluation calls the order total
 * (order-limits/company-order-limits.ts:189-196 compares `cart.total`). `order_item_count` and
 * `unique_items` rules are not enforced here: the product page never states them. There is no
 * step (increment) on the wire (Model\Enforce\OrderLimitProvider), so none is enforced.
 *
 * HOW QUANTITIES ARE COUNTED — summed per SKU across the cart's visible lines, because the same
 * product can sit on several lines and checking each alone would refuse 10 + 20 against a
 * minimum of 25 that the buyer has met (WooCommerce Tack_B2B_Notices::order_limit_violations()).
 * A SKU whose total is zero (removed) is not checked: a minimum applies to what is ordered.
 *
 * WHEN — adding a product (assertAdd), changing line quantities (assertItemQuantities), and
 * order submit (assertCart). A cart change checks only the SKUs it touches, so an unrelated line
 * below its minimum never blocks adding something else; the order submit checks everything.
 *
 * WORDING — "<product>: <the notice's own sentence> You have <n> in your cart.", the sentence
 * coming from Model\B2bDisplay\Presenter::limitSentence(), so the refusal repeats exactly what
 * the product page said.
 *
 * WHO — Model\Enforce\Eligibility (signed-in owner of the cart, inside the B2B customer groups,
 * never an admin or integration caller) and the merchant's switch
 * (`tackquote_quotes/b2b_checkout/enforce_order_limits`, default 0). Any TackQuote failure
 * refuses nothing (Model\Enforce\OrderLimitProvider).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Enforce;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;
use TackQuote\Quotes\Model\B2bDisplay\ResponseMapper;
use TackQuote\Quotes\Model\Config;

class OrderLimitChecker
{
    /** Money closer than this is equal. */
    private const MONEY_EPSILON = 0.00005;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Eligibility
     */
    private $eligibility;

    /**
     * @var OrderLimitProvider
     */
    private $provider;

    /**
     * @var ResponseMapper
     */
    private $responseMapper;

    /**
     * @var AnswerMapper
     */
    private $answerMapper;

    /**
     * @var Presenter
     */
    private $presenter;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @param Config $config
     * @param Eligibility $eligibility
     * @param OrderLimitProvider $provider
     * @param ResponseMapper $responseMapper
     * @param AnswerMapper $answerMapper
     * @param Presenter $presenter
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        Config $config,
        Eligibility $eligibility,
        OrderLimitProvider $provider,
        ResponseMapper $responseMapper,
        AnswerMapper $answerMapper,
        Presenter $presenter,
        PriceCurrencyInterface $priceCurrency
    ) {
        $this->config = $config;
        $this->eligibility = $eligibility;
        $this->provider = $provider;
        $this->responseMapper = $responseMapper;
        $this->answerMapper = $answerMapper;
        $this->presenter = $presenter;
        $this->priceCurrency = $priceCurrency;
    }

    /**
     * Adding $qty of $sku to the cart.
     *
     * @param Quote $quote
     * @param string $sku
     * @param float $qty
     * @param string $name
     * @return void
     * @throws LocalizedException
     */
    public function assertAdd(Quote $quote, string $sku, float $qty, string $name): void
    {
        $buyer = $this->buyer($quote);
        $sku = trim($sku);
        if ($buyer === null || $sku === '') {
            return;
        }

        $totals = $this->totals($quote, []);
        $totals[$sku] = [
            'qty' => ($totals[$sku]['qty'] ?? 0.0) + $qty,
            'name' => $totals[$sku]['name'] ?? $name,
        ];

        $this->refuse($this->quantityViolations($buyer, [$sku => $totals[$sku]]));
    }

    /**
     * Setting the quantity of existing lines: [quote item id => new quantity]. A quantity of 0
     * is a removal.
     *
     * @param Quote $quote
     * @param array<int|string, float> $quantities
     * @return void
     * @throws LocalizedException
     */
    public function assertItemQuantities(Quote $quote, array $quantities): void
    {
        $buyer = $this->buyer($quote);
        if ($buyer === null || $quantities === []) {
            return;
        }

        $quantities = $this->stringKeys($quantities);
        $touched = [];
        foreach ((array) $quote->getAllVisibleItems() as $item) {
            if ($item instanceof Item && array_key_exists((string) $item->getId(), $quantities)) {
                $sku = trim((string) $item->getSku());
                if ($sku !== '') {
                    $touched[$sku] = true;
                }
            }
        }

        $totals = $this->totals($quote, $quantities);
        $this->refuse($this->quantityViolations($buyer, array_intersect_key($totals, $touched)));
    }

    /**
     * The whole cart, at order submit: every quantity bound and every order-total bound.
     *
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    public function assertCart(Quote $quote): void
    {
        $buyer = $this->buyer($quote);
        if ($buyer === null) {
            return;
        }

        $totals = $this->totals($quote, []);
        $messages = $this->quantityViolations($buyer, $totals);

        $bounds = [];
        foreach (array_keys($totals) as $sku) {
            $answer = $this->provider->forSku($buyer, (string) $sku);
            foreach ($this->answerMapper->orderTotalLimits($answer, $buyer['currency']) as $bound) {
                $bounds[$bound['min'] . '|' . $bound['max']] = $bound;
            }
        }

        $total = (float) $quote->getGrandTotal();
        foreach ($bounds as $bound) {
            if ($bound['min'] !== null && $total < $bound['min'] - self::MONEY_EPSILON) {
                $messages[] = (string) __(
                    'The order total must be at least %1.',
                    $this->money($bound['min'], $buyer['currency'])
                );
            }
            if ($bound['max'] !== null && $total > $bound['max'] + self::MONEY_EPSILON) {
                $messages[] = (string) __(
                    'The order total must be at most %1.',
                    $this->money($bound['max'], $buyer['currency'])
                );
            }
        }

        $this->refuse(array_values(array_unique($messages)));
    }

    /**
     * The eligible buyer when the merchant switched enforcement on, else null.
     *
     * @param Quote $quote
     * @return array{storeId: int, customerId: int, email: string, currency: string}|null
     */
    private function buyer(Quote $quote): ?array
    {
        if (!$this->config->isEnforceOrderLimitsEnabled((int) $quote->getStoreId())) {
            return null;
        }

        return $this->eligibility->buyer($quote);
    }

    /**
     * Quantity per SKU across the visible lines, with some lines' quantities replaced.
     *
     * @param Quote $quote
     * @param array<string, float> $overrides [item id => quantity]
     * @return array<string, array{qty: float, name: string}>
     */
    private function totals(Quote $quote, array $overrides): array
    {
        $totals = [];
        foreach ((array) $quote->getAllVisibleItems() as $item) {
            if (!$item instanceof Item || $item->isDeleted()) {
                continue;
            }
            $sku = trim((string) $item->getSku());
            if ($sku === '') {
                continue;
            }
            $id = (string) $item->getId();
            $qty = array_key_exists($id, $overrides) && $id !== '' ? (float) $overrides[$id] : (float) $item->getQty();
            $totals[$sku] = [
                'qty' => ($totals[$sku]['qty'] ?? 0.0) + max(0.0, $qty),
                'name' => $totals[$sku]['name'] ?? (string) $item->getName(),
            ];
        }

        return $totals;
    }

    /**
     * One sentence per SKU whose total breaks its bound.
     *
     * @param array{storeId: int, customerId: int, email: string, currency: string} $buyer
     * @param array<string, array{qty: float, name: string}> $totals
     * @return string[]
     */
    private function quantityViolations(array $buyer, array $totals): array
    {
        $messages = [];
        foreach ($totals as $sku => $line) {
            $qty = $line['qty'];
            if ($qty <= 0) {
                continue;
            }
            $limit = $this->responseMapper->orderLimit($this->provider->forSku($buyer, (string) $sku));
            if ($limit === null) {
                continue;
            }
            $below = $limit['min'] !== null && $qty < $limit['min'];
            $above = $limit['max'] !== null && $qty > $limit['max'];
            if (!$below && !$above) {
                continue;
            }
            $messages[] = (string) __(
                '%1: %2 You have %3 in your cart.',
                $line['name'] !== '' ? $line['name'] : (string) $sku,
                $this->presenter->limitSentence($limit),
                $this->quantity($qty)
            );
        }

        return $messages;
    }

    /**
     * @param string[] $messages
     * @return void
     * @throws LocalizedException
     */
    private function refuse(array $messages): void
    {
        if ($messages !== []) {
            throw new LocalizedException(__('%1', implode(' ', $messages)));
        }
    }

    /**
     * Item ids as string keys (a posted cart form keys them by string, a quote by int).
     *
     * @param array<int|string, mixed> $quantities
     * @return array<string, float>
     */
    private function stringKeys(array $quantities): array
    {
        $out = [];
        foreach ($quantities as $id => $qty) {
            $out[(string) $id] = (float) $qty;
        }

        return $out;
    }

    /**
     * A quantity as the shopper typed it: 25, not 25.0000.
     *
     * @param float $qty
     * @return string
     */
    private function quantity(float $qty): string
    {
        return floor($qty) === $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    /**
     * Money in the cart's currency, without conversion.
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    private function money(float $amount, string $currency): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $currency
        );
    }
}
