<?php
/**
 * Page-cache vary data for the "except approved wholesale accounts" scope.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Every other quote-only input is already in Magento's page-cache key: the customer group
 * (`customer_group`) and signed-in state (`customer_logged_in`) are put into the HTTP context
 * by Magento\Customer\Model\App\Action\ContextPlugin::beforeExecute()
 * (module-customer/Model/App/Action/ContextPlugin.php:47-58, Magento 2.4.8). TackQuote's
 * approval of ONE customer is not: two signed-in customers in the same group can be approved
 * and unapproved. Without a vary value of its own, the page built for the first visitor — Add
 * to Cart removed, or prices replaced — would be served from the cache to the second.
 *
 * Adobe's documented mechanism is a custom HTTP context variable, declared from a
 * `beforeGetVaryString` plugin on Magento\Framework\App\Http\Context, with a DEFAULT value so
 * that a visitor who has it at the default shares the cache with one who has no cookie
 * (developer.adobe.com/commerce/php/development/cache/page/public-content, "Configure page
 * variations" and its CustomerAgeContextPlugin example). The value then travels in the
 * X-Magento-Vary cookie, which both the built-in cache and Varnish hash
 * (Framework/App/PageCache/Identifier.php:58-59, Magento 2.4.8; the sample VCL's vcl_hash).
 *
 * A value equal to its default is left out of the vary data altogether
 * (Http\Context::getData(), Framework/App/Http/Context.php:107-115).
 *
 * The value is a single boolean — approved or not — never the customer: Adobe warns that a
 * per-user context variable caches a copy of every page per user. The default is 0 (not
 * approved), so guests, unapproved customers and every store not using this scope keep
 * exactly the cache entries they had; only approved wholesale customers get a second copy.
 *
 * WHEN IT ASKS. Only when the store-wide mode is configured, on, and scoped to
 * SCOPE_UNAPPROVED, and only for a visitor the HTTP context already marks as signed in. The
 * session is therefore never started for an anonymous page view, and no TackQuote call is
 * made for a guest. The answer is QuoteOnlyMode::isApprovedWholesale() — the same memoised
 * Model\QuoteOnly\WholesaleApproval read the storefront decision uses, cached 5 minutes per
 * customer — so the vary value and the page it keys cannot disagree.
 *
 * UNVERIFIED on a store: that an approval change reaches an already-signed-in customer on
 * their next uncached request (the X-Magento-Vary cookie is re-sent with each response). The
 * PR lists the manual check.
 *
 * Declared in etc/frontend/di.xml only.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\PageCache;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class WholesaleApprovalContext
{
    /** The HTTP context key. Part of every page-cache key once set to a non-default value. */
    public const CONTEXT_KEY = 'tackquote_wholesale_approved';

    /** Not approved — the value every visitor has unless TackQuote says otherwise. */
    public const DEFAULT_VALUE = 0;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param Config $config
     * @param QuoteOnlyMode $quoteOnlyMode
     */
    public function __construct(Config $config, QuoteOnlyMode $quoteOnlyMode)
    {
        $this->config = $config;
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * @param HttpContext $subject
     * @return void
     */
    public function beforeGetVaryString(HttpContext $subject): void
    {
        if (!$this->config->isConfigured()
            || !$this->config->isQuoteOnlyEnabled()
            || $this->config->getQuoteOnlyScope() !== QuoteOnlyRules::SCOPE_UNAPPROVED
        ) {
            return;
        }

        // Read from the context, not the session: an anonymous request never starts one here.
        if (!$subject->getValue(CustomerContext::CONTEXT_AUTH)) {
            return;
        }

        $subject->setValue(
            self::CONTEXT_KEY,
            $this->quoteOnlyMode->isApprovedWholesale() ? 1 : self::DEFAULT_VALUE,
            self::DEFAULT_VALUE
        );
    }
}
