<?php
/**
 * The landing route for an accepted-quote checkout link.
 *
 * Mutation proof:
 *   S1 skip the token shape check before the exchange  -> testAForgedTokenNeverReachesTackQuote
 *   S2 skip the rate limit                             -> testTheRateLimitStopsTheExchange
 *   S3 redirect to checkout on a refused build         -> testARefusedBuildShowsWhyAndNeverOpensCheckout
 *   S4 exchange again for the same link in the session -> testTheSameLinkAgainGoesStraightToCheckout
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Controller\Checkout\Start;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteCheckout\CartBuilder;
use TackQuote\Quotes\Model\QuoteCheckout\CheckoutApiClient;
use TackQuote\Quotes\Model\QuoteCheckout\PayloadReader;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\SubmissionThrottle;

/**
 * @covers \TackQuote\Quotes\Controller\Checkout\Start
 */
class StartTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ0123456';

    /** @var bool */
    private $active = true;

    /** @var bool */
    private $throttled = false;

    /** @var bool */
    private $intact = false;

    /** @var array */
    private $answer = ['ok' => true, 'status' => 200, 'data' => ['valid' => true]];

    /** @var array */
    private $built = ['ok' => true];

    /** @var CheckoutApiClient&MockObject */
    private $client;

    /** @var CartBuilder&MockObject */
    private $builder;

    /** @var DataObject The checkout session's data. */
    private $sessionData;

    /** @var Template */
    private $notice;

    /** @var Redirect&MockObject */
    private $redirect;

    /** @var string|null */
    private $redirectPath;

    private function controller(?string $token = self::TOKEN): Start
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('token')->willReturn($token);
        $config = $this->createMock(Config::class);
        $config->method('isQuoteCheckoutActive')->willReturnCallback(function () {
            return $this->active;
        });
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $quote->method('getId')->willReturn(42);
        $this->sessionData = new DataObject();
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'setQuoteId', 'getData', '__call'])
            ->getMock();
        $session->method('getQuote')->willReturn($quote);
        $session->method('getData')->willReturnCallback(function ($key = '') {
            return $this->sessionData->getData($key);
        });
        // SessionManager::__call() forwards magic setters to its storage, as here.
        $session->method('__call')->willReturnCallback(function ($method, $args) use ($session) {
            if ($method === 'setTackquoteQuoteCheckoutToken') {
                $this->sessionData->setData(Start::SESSION_TOKEN, $args[0] ?? null);
            }

            return $session;
        });
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);
        $this->client = $this->createMock(CheckoutApiClient::class);
        $this->client->method('exchange')->willReturnCallback(function () {
            return $this->answer;
        });
        $reader = $this->createMock(PayloadReader::class);
        $reader->method('read')->willReturnCallback(static function ($data) {
            return is_array($data) && !empty($data['valid']) ? ['quoteNumber' => 'TK-1'] : null;
        });
        $this->builder = $this->createMock(CartBuilder::class);
        $this->builder->method('build')->willReturnCallback(function () {
            return $this->built;
        });
        $quotedCart = $this->createMock(QuotedCart::class);
        $quotedCart->method('isIntact')->willReturnCallback(function () {
            return $this->intact;
        });
        $throttle = $this->createMock(SubmissionThrottle::class);
        $throttle->method('isExceededFor')->willReturnCallback(function () {
            return $this->throttled;
        });
        $this->redirectPath = null;
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function ($path) {
            $this->redirectPath = $path;

            return $this->redirect;
        });
        $redirectFactory = $this->getMockBuilder(RedirectFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->notice = $this->getMockBuilder(Template::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with(Start::NOTICE_BLOCK)->willReturn($this->notice);
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createMock(Title::class));
        $page = $this->createMock(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $page->method('getConfig')->willReturn($pageConfig);
        $pageFactory = $this->getMockBuilder(PageFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $pageFactory->method('create')->willReturn($page);

        return new Start(
            $request,
            $config,
            $session,
            $manager,
            $this->client,
            $reader,
            $this->builder,
            $quotedCart,
            $throttle,
            $redirectFactory,
            $pageFactory
        );
    }

    public function testAnAcceptedQuoteOpensTheCheckout(): void
    {
        $result = $this->controller()->execute();

        self::assertSame($this->redirect, $result);
        self::assertSame('checkout', $this->redirectPath);
        self::assertSame(hash('sha256', self::TOKEN), $this->sessionData->getData(Start::SESSION_TOKEN));
    }

    public function testAForgedTokenNeverReachesTackQuote(): void
    {
        $controller = $this->controller('../../etc/passwd');
        $this->client->expects(self::never())->method('exchange');

        $controller->execute();

        self::assertStringContainsString('not valid', (string) $this->notice->getData('message'));
    }

    public function testASpentLinkSaysSoAndLinksBackToThePortal(): void
    {
        $this->answer = ['ok' => false, 'status' => 410, 'portalUrl' => 'https://portal.example.test/login'];
        $this->controller()->execute();

        self::assertStringContainsString('already used or has expired', (string) $this->notice->getData('message'));
        self::assertSame('https://portal.example.test/login', $this->notice->getData('portal_url'));
        self::assertNull($this->redirectPath);
    }

    public function testARefusedBuildShowsWhyAndNeverOpensCheckout(): void
    {
        $this->built = ['ok' => false, 'message' => __('Quote TK-1 is priced in EUR')];
        $this->controller()->execute();

        self::assertSame('Quote TK-1 is priced in EUR', (string) $this->notice->getData('message'));
        self::assertNull($this->redirectPath);
        self::assertNull($this->sessionData->getData(Start::SESSION_TOKEN));
    }

    public function testAnUnreadablePayloadBuildsNothing(): void
    {
        $this->answer = ['ok' => true, 'status' => 200, 'data' => ['valid' => false]];
        $controller = $this->controller();
        $this->builder->expects(self::never())->method('build');

        $controller->execute();

        self::assertStringContainsString(
            'could not be opened in the store',
            (string) $this->notice->getData('message')
        );
    }

    public function testTheSameLinkAgainGoesStraightToCheckout(): void
    {
        $controller = $this->controller();
        $this->sessionData->setData(Start::SESSION_TOKEN, hash('sha256', self::TOKEN));
        $this->intact = true;
        $this->client->expects(self::never())->method('exchange');

        $controller->execute();

        self::assertSame('checkout', $this->redirectPath);
    }

    public function testTheRateLimitStopsTheExchange(): void
    {
        $this->throttled = true;
        $controller = $this->controller();
        $this->client->expects(self::never())->method('exchange');

        $controller->execute();

        self::assertStringContainsString('Too many checkout links', (string) $this->notice->getData('message'));
    }

    public function testSwitchedOffTheRouteSaysSo(): void
    {
        $this->active = false;
        $controller = $this->controller();
        $this->client->expects(self::never())->method('exchange');

        $controller->execute();

        self::assertStringContainsString('not available in this store', (string) $this->notice->getData('message'));
    }
}
