<?php
/**
 * Page-cache vary data for buyer-group catalog visibility.
 *
 * Hidden categories change public pages: the menu, category listings, search results, and the
 * 404 a hidden category or product answers. Magento's page-cache key already carries the
 * customer group and signed-in state (Customer/Model/App/Action/ContextPlugin.php:47-58), but
 * not the TackQuote buyer group: two customers in the same Magento group can be in different
 * TackQuote groups. Without a value of its own, the page built for one would be served to the
 * other.
 *
 * Same mechanism as Plugin\PageCache\WholesaleApprovalContext (PR #3) and Adobe's documented
 * one: a custom HTTP context value set from a `beforeGetVaryString` plugin on
 * Magento\Framework\App\Http\Context, with a DEFAULT value
 * (developer.adobe.com/commerce/php/development/cache/page/public-content, "Configure page
 * variations"). A value equal to its default is left out of the vary data altogether
 * (Framework/App/Http/Context.php `getData()`), so every visitor with nothing hidden keeps
 * exactly the cache entries they had.
 *
 * THE VALUE is CatalogVisibility::varyValue(): '' (the default) when nothing is hidden from the
 * visitor, otherwise a 16-character hash of the hidden category ids. It names WHAT is hidden,
 * not the group, so groups that see the same catalogue share one copy of each page (Adobe's
 * warning against per-user variation), and no group code appears in the X-Magento-Vary cookie.
 *
 * WHEN IT ASKS. Only while catalog visibility is on, connected and has a rule. The hidden set
 * was already decided before the action ran (Plugin\PageCache\BuyerGroupWarmup, because the
 * page cache depersonalises the session once the layout is generated) and is memoised for the
 * request, so this reads the same answer the menu, the listings and the product page used:
 * the vary value and the page it keys cannot disagree. A guest needs no TackQuote call (a
 * guest is `@GUESTS` by definition); a signed-in customer's group is the 60-second-cached
 * buyer-group read.
 *
 * Declared in etc/frontend/di.xml only.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\PageCache;

use Magento\Framework\App\Http\Context as HttpContext;
use TackQuote\Quotes\Model\Groups\CatalogVisibility;

class BuyerGroupContext
{
    /** The HTTP context key. Part of every page-cache key once set to a non-default value. */
    public const CONTEXT_KEY = 'tackquote_buyer_group';

    /** Nothing hidden: the value every visitor has unless a rule hides something from them. */
    public const DEFAULT_VALUE = '';

    /**
     * @var CatalogVisibility
     */
    private $visibility;

    /**
     * @param CatalogVisibility $visibility
     */
    public function __construct(CatalogVisibility $visibility)
    {
        $this->visibility = $visibility;
    }

    /**
     * @param HttpContext $subject
     * @return void
     */
    public function beforeGetVaryString(HttpContext $subject): void
    {
        $value = $this->visibility->varyValue();
        if ($value === self::DEFAULT_VALUE) {
            return;
        }

        $subject->setValue(self::CONTEXT_KEY, $value, self::DEFAULT_VALUE);
    }
}
