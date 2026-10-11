<?php
/**
 * The full quote page: `GET /tackquote/quote` (route `tackquote`, controller `quote`, action
 * `index`, so layout handle `tackquote_quote_index`).
 *
 * A Magento page of its own rather than a CMS page carrying a widget: nothing for the merchant
 * to create, and no CMS content to keep in step with the module. The page shows the shopper's
 * quote list as a table (quantities, an optional target price per line, a message) and opens
 * the same quote form the drawer uses (Block\QuoteList, rendered site-wide). Parity with the
 * WooCommerce plugin's `[tackquote_quote_page]` shortcode (Tack_Widget::render_quote_page()).
 *
 * The page renders no per-visitor state: the list lives in the browser's localStorage and is
 * drawn client-side, so the HTML is safe for the full-page cache.
 *
 * Switched off, the route answers Magento's own 404 page (a forward to `noroute`), so the URL
 * does not exist for a store that has not turned the page on. Pattern: Adobe Commerce developer
 * docs, "Routing" (an Action implementing HttpGetActionInterface returning a page result).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Quote;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use TackQuote\Quotes\Model\QuoteRequestConfig;

class Index implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @var ForwardFactory
     */
    private $forwardFactory;

    /**
     * @var QuoteRequestConfig
     */
    private $quoteRequestConfig;

    /**
     * @param PageFactory $pageFactory
     * @param ForwardFactory $forwardFactory
     * @param QuoteRequestConfig $quoteRequestConfig
     */
    public function __construct(
        PageFactory $pageFactory,
        ForwardFactory $forwardFactory,
        QuoteRequestConfig $quoteRequestConfig
    ) {
        $this->pageFactory = $pageFactory;
        $this->forwardFactory = $forwardFactory;
        $this->quoteRequestConfig = $quoteRequestConfig;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        if (!$this->quoteRequestConfig->isQuotePageEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Your quote list'));

        return $page;
    }
}
