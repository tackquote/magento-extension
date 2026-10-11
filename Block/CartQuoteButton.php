<?php
/**
 * "Request a quote for your cart" on the cart page, in normal mode.
 *
 * The button only COPIES the cart's SKUs and quantities into the shopper's quote list, in the
 * browser (view/frontend/web/js/cart-seed.js reads the `cart` customer-data section, the
 * minicart's own source), and then opens the quote list. The Magento cart is not read on the
 * server, not changed, and not linked to the list afterwards: the list and the cart stay
 * separate, as everywhere else in this module.
 *
 * Off by default (`quote_request/enable_cart_button`). Not rendered while quote-only mode is
 * active for the visitor: that mode puts its own panel and quote action on this page
 * (Block\QuoteOnlyNotice), and two quote calls to action on one page would compete.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Model\QuoteRequestConfig;

class CartQuoteButton extends Template
{
    /**
     * @var QuoteRequestConfig
     */
    private $quoteRequestConfig;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param Context $context
     * @param QuoteRequestConfig $quoteRequestConfig
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param array $data
     */
    public function __construct(
        Context $context,
        QuoteRequestConfig $quoteRequestConfig,
        QuoteOnlyMode $quoteOnlyMode,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->quoteRequestConfig = $quoteRequestConfig;
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * Whether the button renders.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->quoteRequestConfig->isCartButtonEnabled() && !$this->quoteOnlyMode->isActive();
    }

    /**
     * The button label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->quoteRequestConfig->getCartButtonLabel();
    }
}
