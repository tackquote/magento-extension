<?php
/**
 * Payment and shipping methods restricted by TackQuote buyer group, and shipping discounts per
 * group, for one cart.
 *
 * Port of WooCommerce 1.11.0 class-tack-group-restrictions.php. The rules themselves are
 * GroupRules::isPermitted() (allow-list, unconfigured = unrestricted, an outage leaves methods
 * available unless the merchant chose the strict reading) and GroupRules::discountedPrice().
 *
 * NEVER EMPTY THE LIST. WooCommerce's rule, kept: "A checkout with no payment method is a
 * checkout nobody can complete, and a misconfiguration should degrade to 'too many options'
 * rather than 'no way to pay'." For shipping, filterRates() keeps the original rates when the
 * rules would remove every one. For payment the decision is made per method (the
 * `payment_method_is_active` observer, which is also what refuses a hidden method at order
 * placement), so the guard lives in Plugin\Payment\MethodListRestriction: when the rules leave
 * a cart no method at all, it suspends them for THAT cart (suspendPayment()), for
 * SUSPEND_TTL, so the method list and the later "set payment method" request agree.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Framework\App\CacheInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Psr\Log\LoggerInterface;

class MethodRestrictions
{
    private const SUSPEND_PREFIX = 'tackquote_pay_suspended_';

    /** Seconds the payment rules stay suspended for a cart they would have left with nothing. */
    public const SUSPEND_TTL = 900;

    /**
     * @var GroupRulesConfig
     */
    private $config;

    /**
     * @var Audience
     */
    private $audience;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param GroupRulesConfig $config
     * @param Audience $audience
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        GroupRulesConfig $config,
        Audience $audience,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->audience = $audience;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Do payment rules exist for this store at all? (Cheap: no visitor or TackQuote lookup.)
     *
     * @param int $storeId
     * @return bool
     */
    public function hasPaymentRules(int $storeId): bool
    {
        return $this->config->isRestrictionsActive($storeId) && $this->config->getPaymentMap($storeId) !== [];
    }

    /**
     * May this cart's customer pay with this method?
     *
     * @param string $methodCode Magento payment method code (`checkmo`, `purchaseorder`, ...).
     * @param CartInterface $quote
     * @return bool
     */
    public function isPaymentPermitted(string $methodCode, CartInterface $quote): bool
    {
        $storeId = (int) $quote->getStoreId();
        if (!$this->hasPaymentRules($storeId)) {
            return true;
        }
        $map = $this->config->getPaymentMap($storeId);
        if (!isset($map[$methodCode]) || $this->isPaymentSuspended($quote)) {
            return true;
        }

        return GroupRules::isPermitted(
            $map,
            $methodCode,
            $this->audience->forQuote($quote),
            $this->config->restrictWhenUnknown($storeId)
        );
    }

    /**
     * Stop applying payment rules to this cart for SUSPEND_TTL (see the class docblock).
     *
     * @param CartInterface $quote
     * @return void
     */
    public function suspendPayment(CartInterface $quote): void
    {
        $id = $this->suspendId($quote);
        if ($id === '') {
            return;
        }
        $this->cache->save('1', $id, [BuyerGroupResolver::CACHE_TAG], self::SUSPEND_TTL);
    }

    /**
     * Apply the payment rules to this cart again (the rules were not why it had no method).
     *
     * @param CartInterface $quote
     * @return void
     */
    public function resumePayment(CartInterface $quote): void
    {
        $id = $this->suspendId($quote);
        if ($id !== '') {
            $this->cache->remove($id);
        }
    }

    /**
     * Say in the log that the rules were suspended for a cart.
     *
     * @return void
     */
    public function logPaymentSuspended(): void
    {
        $this->logger->warning(
            'TackQuote buyer-group rules would have removed EVERY payment method from a cart; '
            . 'leaving them all available. Check the payment rules under Buyer group rules.'
        );
    }

    /**
     * @param CartInterface $quote
     * @return bool
     */
    public function isPaymentSuspended(CartInterface $quote): bool
    {
        $id = $this->suspendId($quote);

        return $id !== '' && (bool) $this->cache->load($id);
    }

    /**
     * Apply the shipping restrictions, then the shipping discount, to one cart's rates.
     *
     * Same order as WooCommerce's one `woocommerce_package_rates` callback: which methods this
     * group may use at all first, then what the remaining ones cost. Error results (a carrier
     * that could not quote) are kept as they are.
     *
     * @param array $rates Rate result objects (RateResult\Method and RateResult\Error).
     * @param CartInterface $quote
     * @return array|null The list to keep, or null when no rule applies (leave the result alone).
     */
    public function applyToRates(array $rates, CartInterface $quote): ?array
    {
        $storeId = (int) $quote->getStoreId();
        $shippingMap = $this->config->isRestrictionsActive($storeId) ? $this->config->getShippingMap($storeId) : [];
        $discounts = $this->config->isDiscountsActive($storeId) ? $this->config->getDiscountMap($storeId) : [];
        if (($shippingMap === [] && $discounts === []) || !$this->hasMethods($rates)) {
            return null;
        }

        $answer = $this->audience->forQuote($quote);
        if ($answer->isExempt()) {
            return null;
        }

        if ($shippingMap !== []) {
            $rates = $this->filterRates($rates, $shippingMap, $answer, $this->config->restrictWhenUnknown($storeId));
        }
        if ($discounts !== []) {
            $rates = $this->discountRates($rates, $discounts, $answer);
        }

        return $rates;
    }

    /**
     * Remove the rates this group may not use, never all of them.
     *
     * @param array $rates
     * @param array<string, string[]> $map
     * @param BuyerGroupAnswer $answer
     * @param bool $restrictWhenUnknown
     * @return array
     */
    public function filterRates(array $rates, array $map, BuyerGroupAnswer $answer, bool $restrictWhenUnknown): array
    {
        $out = [];
        foreach ($rates as $rate) {
            if (!$rate instanceof Method) {
                $out[] = $rate;
                continue;
            }
            $key = GroupRules::shippingRuleKey($map, (string) $rate->getCarrier(), (string) $rate->getMethod());
            if (GroupRules::isPermitted($map, $key, $answer, $restrictWhenUnknown)) {
                $out[] = $rate;
            }
        }

        if (!$this->hasMethods($out)) {
            $this->logger->warning(
                'TackQuote buyer-group rules would have removed EVERY shipping rate; leaving them all '
                . 'available. Check the shipping rules under Buyer group rules.'
            );

            return $rates;
        }

        return $out;
    }

    /**
     * Apply this group's shipping discount. Only a buyer TackQuote places in a group gets one:
     * a discount is a grant, so an unknown group, no group and guests pay the normal rate.
     *
     * @param array $rates
     * @param array<string, array{mode: string, percent: float, methods: string[]}> $rules
     * @param BuyerGroupAnswer $answer
     * @return array The same objects; discounted ones have a new price.
     */
    public function discountRates(array $rates, array $rules, BuyerGroupAnswer $answer): array
    {
        if ($answer->getStatus() !== BuyerGroupAnswer::GROUPED || !isset($rules[$answer->getCode()])) {
            return $rates;
        }
        $rule = $rules[$answer->getCode()];

        if ($rule['mode'] === GroupRules::DISCOUNT_FREE_ONLY) {
            $out = [];
            $free = 0;
            foreach ($rates as $rate) {
                if (!$rate instanceof Method) {
                    $out[] = $rate;
                    continue;
                }
                if ((float) $rate->getPrice() <= 0) {
                    $out[] = $rate;
                    $free++;
                }
            }
            if ($free === 0) {
                // Never empty the list: no free rate exists, so nothing is removed.
                return $rates;
            }

            return $out;
        }

        foreach ($rates as $rate) {
            if (!$rate instanceof Method
                || !GroupRules::discountCovers($rule['methods'], (string) $rate->getCarrier(), (string) $rate->getMethod())
            ) {
                continue;
            }
            $price = (float) $rate->getPrice();
            $new = GroupRules::discountedPrice($price, $rule);
            if ($new !== $price) {
                $rate->setPrice($new);
            }
        }

        return $rates;
    }

    /**
     * @param array $rates
     * @return bool
     */
    private function hasMethods(array $rates): bool
    {
        foreach ($rates as $rate) {
            if ($rate instanceof Method) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param CartInterface $quote
     * @return string '' for a cart that was never saved (nothing to remember it by).
     */
    private function suspendId(CartInterface $quote): string
    {
        $id = (int) $quote->getId();

        return $id > 0 ? self::SUSPEND_PREFIX . $id : '';
    }
}
