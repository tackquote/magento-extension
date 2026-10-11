<?php
/**
 * The accepted-quote checkout link: `GET /tackquote/checkout/start?token=<token>`
 * (route `tackquote`, controller `checkout`, action `start`; layout handle
 * `tackquote_checkout_start`).
 *
 * TackQuote's buyer portal sends a buyer here after they accept a quote and press "Checkout in
 * the store". The module tells TackQuote this URL on every ping (`X-TackQuote-Quote-Checkout-Url`,
 * Model\QuoteCheckout\LandingUrl); TackQuote accepts it only on the host the seller entered for
 * the store connection. This action:
 *
 *   1. checks the token's shape (nothing else ever reaches the network) and a per-visitor rate
 *      limit (10 links per 10 minutes, the WooCommerce plugin's);
 *   2. exchanges it SERVER-TO-SERVER for the accepted lines (Model\QuoteCheckout\CheckoutApiClient,
 *      the store's API key), once: TackQuote spends a token on its first successful exchange;
 *   3. builds the cart (Model\QuoteCheckout\CartBuilder): every line at its quoted quantity and
 *      price, or nothing;
 *   4. redirects to Magento's own checkout.
 *
 * The same link opened again in the same session (the back button, a second tab) after it was
 * spent here goes straight to the checkout while the cart still holds that quote intact.
 *
 * Anything that fails renders a page saying what happened, with a link back to the buyer portal
 * when TackQuote names one (an expired or used link). The page is not cacheable
 * (`cacheable="false"` in the layout) and the token is never put in a redirect target.
 *
 * Off (Stores > Configuration > TackQuote > Accepted quote checkout) or unconfigured: the page
 * says the checkout link is not available in this store.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteCheckout\CartBuilder;
use TackQuote\Quotes\Model\QuoteCheckout\CheckoutApiClient;
use TackQuote\Quotes\Model\QuoteCheckout\PayloadReader;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\SubmissionThrottle;

class Start implements HttpGetActionInterface
{
    /** Checkout-session key holding the SHA-256 of the token this session spent. */
    public const SESSION_TOKEN = 'tackquote_quote_checkout_token';

    /** The notice block in view/frontend/layout/tackquote_checkout_start.xml. */
    public const NOTICE_BLOCK = 'tackquote.quote.checkout.notice';

    /** Links one visitor may open per window (Tack_Quote_Checkout::RATE_LIMIT_MAX). */
    private const RATE_LIMIT_MAX = 10;

    /** Seconds (Tack_Quote_Checkout::RATE_LIMIT_WINDOW). */
    private const RATE_LIMIT_WINDOW = 600;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CheckoutApiClient
     */
    private $client;

    /**
     * @var PayloadReader
     */
    private $reader;

    /**
     * @var CartBuilder
     */
    private $builder;

    /**
     * @var QuotedCart
     */
    private $quotedCart;

    /**
     * @var SubmissionThrottle
     */
    private $throttle;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @param RequestInterface $request
     * @param Config $config
     * @param CheckoutSession $checkoutSession Injected as a Proxy (etc/frontend/di.xml).
     * @param StoreManagerInterface $storeManager
     * @param CheckoutApiClient $client
     * @param PayloadReader $reader
     * @param CartBuilder $builder
     * @param QuotedCart $quotedCart
     * @param SubmissionThrottle $throttle
     * @param RedirectFactory $redirectFactory
     * @param PageFactory $pageFactory
     */
    public function __construct(
        RequestInterface $request,
        Config $config,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        CheckoutApiClient $client,
        PayloadReader $reader,
        CartBuilder $builder,
        QuotedCart $quotedCart,
        SubmissionThrottle $throttle,
        RedirectFactory $redirectFactory,
        PageFactory $pageFactory
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->client = $client;
        $this->reader = $reader;
        $this->builder = $builder;
        $this->quotedCart = $quotedCart;
        $this->throttle = $throttle;
        $this->redirectFactory = $redirectFactory;
        $this->pageFactory = $pageFactory;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isQuoteCheckoutActive($storeId)) {
            return $this->notice(__('Opening a quote in the checkout is not available in this store.'));
        }

        $token = $this->request->getParam('token');
        if (!is_string($token) || preg_match(CheckoutApiClient::TOKEN_PATTERN, $token) !== 1) {
            return $this->notice(
                __('This checkout link is not valid. Please open the quote again and use its checkout button.')
            );
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            $quote = null;
        }
        if (!$quote instanceof Quote) {
            return $this->notice(
                __(
                    'The cart is not available right now, so this quote could not be opened. Please try '
                    . 'again from the quote.'
                )
            );
        }

        $hash = hash('sha256', $token);
        $spent = $this->checkoutSession->getData(self::SESSION_TOKEN);
        if (is_string($spent) && hash_equals($spent, $hash) && $this->quotedCart->isIntact($quote)) {
            return $this->redirectFactory->create()->setPath('checkout');
        }

        if ($this->throttle->isExceededFor('quotecheckout', self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            return $this->notice(
                __('Too many checkout links were opened from this connection. Please wait a few minutes and try again.')
            );
        }

        $answer = $this->client->exchange($token, $storeId);
        if (!$answer['ok']) {
            $portalUrl = (string) ($answer['portalUrl'] ?? '');
            if ($answer['status'] === 410) {
                return $this->notice(
                    __(
                        'This checkout link was already used or has expired. Please open the quote again to '
                        . 'get a new link.'
                    ),
                    $portalUrl
                );
            }
            if ($answer['status'] === 404) {
                return $this->notice(
                    __('This checkout link is not valid. Please open the quote again and use its checkout button.'),
                    $portalUrl
                );
            }

            return $this->notice(
                __('This quote could not be opened right now. Please open the quote again and use its checkout button.')
            );
        }

        $payload = $this->reader->read($answer['data'] ?? null);
        if ($payload === null) {
            return $this->notice(__('This quote could not be opened in the store. Please contact the seller.'));
        }

        $built = $this->builder->build($quote, $payload, $storeId);
        if (!$built['ok']) {
            return $this->notice($built['message']);
        }

        $this->checkoutSession->setQuoteId((int) $quote->getId());
        // SessionManager has no setData(); its magic setter writes the storage key
        // `tackquote_quote_checkout_token` (self::SESSION_TOKEN), which getData() reads back.
        $this->checkoutSession->setTackquoteQuoteCheckoutToken($hash);

        return $this->redirectFactory->create()->setPath('checkout');
    }

    /**
     * The notice page: what happened, and a way back to the quote.
     *
     * @param Phrase $message
     * @param string $portalUrl
     * @return \Magento\Framework\View\Result\Page
     */
    private function notice(Phrase $message, string $portalUrl = '')
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Quote checkout'));
        $block = $page->getLayout()->getBlock(self::NOTICE_BLOCK);
        if ($block) {
            $block->setData('message', (string) $message);
            $block->setData('portal_url', $portalUrl);
        }

        return $page;
    }
}
