<?php
/**
 * View model of the full quote page (Controller\Quote\Index, template quote-page.phtml).
 *
 * Cacheable and visitor-independent: it renders only the page's shell and the merchant's
 * switches. The rows are drawn client-side from the shopper's localStorage list by
 * quote-app.js, and the request itself goes through the one shared quote form
 * (Block\QuoteList), so the page adds no second form, no second submit path.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteRequestConfig;

class QuotePage extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QuoteRequestConfig
     */
    private $quoteRequestConfig;

    /**
     * @param Context $context
     * @param Config $config
     * @param QuoteRequestConfig $quoteRequestConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        QuoteRequestConfig $quoteRequestConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->quoteRequestConfig = $quoteRequestConfig;
    }

    /**
     * Whether the page renders at all (the controller also 404s when it is off).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->quoteRequestConfig->isQuotePageEnabled();
    }

    /**
     * Whether the "Target price" column renders.
     *
     * @return bool
     */
    public function isTargetPriceEnabled(): bool
    {
        return $this->quoteRequestConfig->isTargetPriceEnabled();
    }

    /**
     * Label of the button that opens the quote form with the whole list.
     *
     * @return string
     */
    public function getSubmitLabel(): string
    {
        return $this->config->getCheckoutButtonLabel();
    }

    /**
     * "Continue shopping": the store's home page.
     *
     * @return string
     */
    public function getContinueUrl(): string
    {
        return $this->getBaseUrl();
    }
}
