<?php
/**
 * Quote-only (B2B catalog) mode — is it active for THIS storefront visitor?
 *
 * One instance of the answer, shared by everything that needs it:
 *
 *   Plugin\Checkout\CartGuard          refuses the add-to-cart POST
 *   Plugin\Checkout\CheckoutGuard      refuses the checkout page and frontend order placement
 *   Observer\QuoteOnlyLayoutHandle     removes the Add to Cart button from the product page
 *   Block\RequestQuote                 renders the quote CTA in its place
 *
 * THE ONE CONDITION THAT KEEPS THE STOREFRONT ALIVE.
 *
 * isActive() starts with Config::isConfigured(), and that is load-bearing rather than an
 * optimisation. Config::isButtonEnabled() and Config::isAddToQuoteEnabled() both already
 * gate on isConfigured(), so on a store with no API key NO quote control renders anywhere —
 * a button that cannot reach TackQuote drops the shopper's request on the floor. If
 * enforcement did not honour the same condition, a merchant who enabled quote-only before
 * pasting their key would get a storefront with the cart refused AND no quote button:
 * nothing works in either direction, and nothing in the admin says why. Enforcement and CTA
 * are switched on by the same condition on purpose, and Test/Unit asserts both halves.
 *
 * WHO IS ASKING comes from Model\QuoteOnly\VisitorContextInterface, bound per area: the
 * customer session on the storefront, the Web API / GraphQL user context in those areas
 * (where the merchant's admin and integrations — TackQuote's own quote conversion
 * included — are EXEMPT), and an always-exempt visitor everywhere else.
 *
 * FULL-PAGE CACHE. Everything this class reads is either store-scoped config or one of two
 * customer facts — signed-in, and customer group — and Magento already varies the FPC entry
 * on exactly those two: Magento\Customer\Model\App\Action\ContextPlugin sets
 * Context::CONTEXT_GROUP (line 50) and Context::CONTEXT_AUTH (line 55) into the HTTP context
 * on every frontend action, declared as a plugin on Magento\Framework\App\ActionInterface in
 * vendor/magento/module-customer/etc/frontend/di.xml:23-26. So the layout change this
 * decision drives is cached per group and per signed-in state without this module adding a
 * custom vary dimension. Adding a THIRD input to this class — anything not already in that
 * context, such as a cookie, the time of day, or the specific customer id — would silently
 * serve one visitor's storefront to another, and would need its own
 * HttpContext::setValue() before it could be trusted.
 *
 * The "except approved wholesale accounts" scope adds exactly such an input — TackQuote's
 * answer about one customer — and therefore ships with its own vary value:
 * Plugin\PageCache\WholesaleApprovalContext puts `tackquote_wholesale_approved` into the HTTP
 * context, the mechanism Adobe documents for this
 * (developer.adobe.com/commerce/php/development/cache/page/public-content, "Configure page
 * variations": a `beforeGetVaryString` plugin on Magento\Framework\App\Http\Context).
 * Both read the same Model\QuoteOnly\WholesaleApproval answer.
 *
 * A PRODUCT's own quote-only flag is not a visitor input at all: it is part of the product,
 * whose pages and listings Magento already caches per product and cleans on product save.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\CartInterface;
use TackQuote\Quotes\Model\QuoteOnly\ProductFlag;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;
use TackQuote\Quotes\Model\QuoteOnly\WholesaleApproval;

class QuoteOnlyMode
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var VisitorContextInterface
     */
    private $visitor;

    /**
     * @var ProductFlag
     */
    private $productFlag;

    /**
     * @var WholesaleApproval
     */
    private $wholesaleApproval;

    /**
     * @param Config $config
     * @param VisitorContextInterface $visitor Bound per area (etc/frontend, etc/webapi_rest,
     *                                         etc/webapi_soap, etc/graphql, global etc/di.xml).
     * @param ProductFlag $productFlag
     * @param WholesaleApproval $wholesaleApproval
     */
    public function __construct(
        Config $config,
        VisitorContextInterface $visitor,
        ProductFlag $productFlag,
        WholesaleApproval $wholesaleApproval
    ) {
        $this->config = $config;
        $this->visitor = $visitor;
        $this->productFlag = $productFlag;
        $this->wholesaleApproval = $wholesaleApproval;
    }

    /**
     * Does the STORE-WIDE quote-only mode apply to this visitor?
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        // See "THE ONE CONDITION" above. Do not reorder this below the enable check without
        // reading it: on an unconfigured store this is the difference between "quote-only is
        // off" and "the storefront cannot transact at all".
        if (!$this->config->isConfigured($storeId)) {
            return false;
        }

        if (!$this->config->isQuoteOnlyEnabled($storeId)) {
            return false;
        }

        if ($this->visitor->isExempt()) {
            return false;
        }

        $scope = $this->config->getQuoteOnlyScope($storeId);
        $loggedIn = $this->visitor->isLoggedIn();

        return QuoteOnlyRules::applies(
            true,
            $scope,
            QuoteOnlyRules::normaliseGroups($this->config->getQuoteOnlyCustomerGroups($storeId)),
            $loggedIn,
            $this->visitor->getCustomerGroupId(),
            // Asked ONLY for this scope and a signed-in visitor: a guest costs no API call,
            // and the other scopes never reach TackQuote at all.
            $scope === QuoteOnlyRules::SCOPE_UNAPPROVED && $loggedIn
                && $this->isApprovedWholesale($storeId)
        );
    }

    /**
     * TackQuote's answer for the signed-in visitor (false for a guest or on any failure).
     *
     * Public because Plugin\PageCache\WholesaleApprovalContext puts the same answer into the
     * page-cache vary data; two reads of one memoised source cannot disagree.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isApprovedWholesale(?int $storeId = null): bool
    {
        if (!$this->visitor->isLoggedIn()) {
            return false;
        }

        return $this->wholesaleApproval->isApproved(
            $this->visitor->getCustomerEmail(),
            $this->visitor->getCustomerId(),
            $storeId
        );
    }

    /**
     * Is this PRODUCT quote-only for this visitor, through its own flag?
     *
     * Independent of the store-wide switch and of "Applies to" (WooCommerce parity), but
     * still behind the same two conditions as everything else: a configured module (so a
     * quote button can render — enforcement without a CTA is a dead product page) and a
     * caller that is not exempt (the admin and integrations, TackQuote's quote conversion
     * included, keep buying it).
     *
     * @param mixed $product
     * @param int|null $storeId
     * @return bool
     */
    public function isProductQuoteOnly($product, ?int $storeId = null): bool
    {
        if (!$this->config->isConfigured($storeId) || $this->visitor->isExempt()) {
            return false;
        }

        return $this->productFlag->isQuoteOnly($product, $storeId);
    }

    /**
     * The same question by product id (layout handles, cart lines, reorder).
     *
     * @param int $productId
     * @param int|null $storeId
     * @return bool
     */
    public function isProductIdQuoteOnly(int $productId, ?int $storeId = null): bool
    {
        if (!$this->config->isConfigured($storeId) || $this->visitor->isExempt()) {
            return false;
        }

        return $this->productFlag->isQuoteOnlyId($productId, $storeId);
    }

    /**
     * May this visitor NOT buy this product? Store-wide mode, or the product's own flag.
     *
     * @param mixed $product
     * @param int|null $storeId
     * @return bool
     */
    public function appliesToProduct($product, ?int $storeId = null): bool
    {
        return $this->isActive($storeId) || $this->isProductQuoteOnly($product, $storeId);
    }

    /**
     * Does this cart hold a line that is quote-only through its product's own flag?
     *
     * Every item is checked, children included, so a flagged simple inside a configurable
     * line is caught at checkout even though it entered through its parent.
     *
     * @param CartInterface|null $cart
     * @return bool
     */
    public function cartHasQuoteOnlyProduct(?CartInterface $cart): bool
    {
        if ($cart === null || !method_exists($cart, 'getAllItems')) {
            return false;
        }

        foreach ((array) $cart->getAllItems() as $item) {
            $productId = $item instanceof DataObject ? (int) $item->getProductId() : 0;
            if ($productId > 0 && $this->isProductIdQuoteOnly($productId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May this cart NOT be converted into an order by this visitor?
     *
     * @param CartInterface|null $cart
     * @return bool
     */
    public function appliesToCart(?CartInterface $cart): bool
    {
        return $this->isActive() || $this->cartHasQuoteOnlyProduct($cart);
    }

    /**
     * "Price on request": only where the STORE-WIDE mode applies to this visitor and the
     * merchant switched price hiding on. See QuoteOnlyRules::hidesPrices().
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldHidePrices(?int $storeId = null): bool
    {
        if (!$this->config->isQuoteOnlyHidePrices($storeId)) {
            return false;
        }

        return QuoteOnlyRules::hidesPrices($this->isActive($storeId), true);
    }
}
