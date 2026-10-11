<?php
/**
 * POST /tackquote/b2b/display: who is asked about, and when the answer is empty.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Controller\B2b;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Controller\B2b\Display;
use TackQuote\Quotes\Model\B2bDisplay\DataProvider;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Controller\B2b\Display
 */
class DisplayTest extends TestCase
{
    private const PRESENTED = ['accountPrice' => ['price' => 'presented'], 'quantityBreaks' => null,
        'orderLimit' => null, 'buyerGroup' => null];

    /** @var RequestInterface&MockObject */
    private $request;

    /** @var CustomerSession&MockObject */
    private $session;

    /** @var Store&MockObject */
    private $store;

    /** @var Product&MockObject */
    private $product;

    /** @var ProductRepositoryInterface&MockObject */
    private $repository;

    /** @var Config&MockObject */
    private $config;

    /** @var DataProvider&MockObject */
    private $provider;

    /** @var Presenter&MockObject */
    private $presenter;

    /** @var array|null What the controller answered. */
    private $answered;

    /** @var array<string, string> Headers the controller set. */
    private $headers = [];

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(static function ($name, $default = null) {
            return $name === 'product_id' ? '7' : $default;
        });

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('buyer@example.test');
        $this->session = $this->createMock(CustomerSession::class);
        $this->session->method('getCustomerData')->willReturn($customer);
        $this->session->method('getCustomerId')->willReturn(42);

        $this->store = $this->createMock(Store::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getWebsiteId')->willReturn(1);
        $this->store->method('getCurrentCurrencyCode')->willReturn('USD');

        $this->product = $this->createMock(Product::class);
        $this->product->method('getStatus')->willReturn(1);
        $this->product->method('isVisibleInSiteVisibility')->willReturn(true);
        $this->product->method('getWebsiteIds')->willReturn(['1']);
        $this->product->method('getSku')->willReturn('SKU-1');
        $this->repository = $this->createMock(ProductRepositoryInterface::class);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getEnabledB2bFeatures')->willReturn(Config::B2B_FEATURES);

        $this->provider = $this->createMock(DataProvider::class);
        $this->provider->method('get')->willReturn(DataProvider::EMPTY_RESULT);
        $this->presenter = $this->createMock(Presenter::class);
        $this->presenter->method('present')->willReturn(self::PRESENTED);
    }

    private function controller(): Display
    {
        $result = $this->createMock(JsonResult::class);
        $result->method('setHeader')->willReturnCallback(function ($name, $value) use ($result) {
            $this->headers[$name] = $value;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use ($result) {
            $this->answered = $data;

            return $result;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);

        return new Display(
            $this->request,
            $factory,
            $this->session,
            $storeManager,
            $this->repository,
            $this->config,
            $this->provider,
            $this->presenter
        );
    }

    private function signedIn(int $group = 1): void
    {
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerGroupId')->willReturn($group);
    }

    public function testItIsAPostOnlyControllerUnderMagentosBuiltInCsrfCheck(): void
    {
        $controller = $this->controller();

        self::assertInstanceOf(HttpPostActionInterface::class, $controller);
        // GET is cacheable and would put a customer's prices in the page cache.
        self::assertNotInstanceOf(HttpGetActionInterface::class, $controller);
        // Implementing CsrfAwareActionInterface could switch the form-key check off.
        self::assertNotInstanceOf(CsrfAwareActionInterface::class, $controller);
    }

    public function testAGuestGetsNothingAndTackQuoteIsNotAsked(): void
    {
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->provider->expects($this->never())->method('get');
        $this->repository->expects($this->never())->method('getById');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }

    public function testTheAnswerIsNeverCacheable(): void
    {
        $this->session->method('isLoggedIn')->willReturn(false);

        $this->controller()->execute();

        self::assertStringContainsString('no-store', $this->headers['Cache-Control'] ?? '');
        self::assertStringContainsString('private', $this->headers['Cache-Control'] ?? '');
    }

    public function testTheSignedInCustomersOwnEmailIsTheOnlyIdentityUsed(): void
    {
        $this->signedIn();
        // A crafted request naming somebody else changes nothing: only product_id is read.
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(static function ($name, $default = null) {
            $params = ['product_id' => '7', 'email' => 'victim@example.test', 'buyerEmail' => 'victim@example.test'];

            return $params[$name] ?? $default;
        });
        $this->repository->method('getById')->with(7, false, 1)->willReturn($this->product);
        $this->provider->expects($this->once())->method('get')
            ->with(1, 42, 'buyer@example.test', 'SKU-1', 'USD', null)
            ->willReturn(DataProvider::EMPTY_RESULT);

        $this->controller()->execute();

        self::assertSame(self::PRESENTED, $this->answered);
    }

    public function testNoSwitchOnMeansNoWork(): void
    {
        $this->signedIn();
        $this->config = $this->createMock(Config::class);
        $this->config->method('getEnabledB2bFeatures')->willReturn([]);
        $this->repository->expects($this->never())->method('getById');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }

    public function testACustomerOutsideTheSelectedGroupsGetsNothing(): void
    {
        $this->signedIn(1);
        $this->config->method('getB2bCustomerGroups')->willReturn('2,3');
        // Everything else would succeed, so the group filter is the only thing that can stop it.
        $this->repository->method('getById')->willReturn($this->product);
        $this->provider->expects($this->never())->method('get');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }

    public function testACustomerInsideTheSelectedGroupsIsServed(): void
    {
        $this->signedIn(3);
        $this->config->method('getB2bCustomerGroups')->willReturn('2,3');
        $this->repository->method('getById')->willReturn($this->product);
        $this->provider->expects($this->once())->method('get')->willReturn(DataProvider::EMPTY_RESULT);

        $this->controller()->execute();

        self::assertSame(self::PRESENTED, $this->answered);
    }

    public function testAnUnknownProductGetsNothing(): void
    {
        $this->signedIn();
        $this->repository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->provider->expects($this->never())->method('get');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function hiddenProducts(): array
    {
        return [
            'disabled' => ['getStatus', 2],
            'not visible' => ['isVisibleInSiteVisibility', false],
            'other website' => ['getWebsiteIds', ['2']],
            'no sku' => ['getSku', ''],
        ];
    }

    /**
     * @dataProvider hiddenProducts
     * @param string $method
     * @param mixed $value
     */
    public function testAProductTheStoreWouldNotShowGetsNothing(string $method, $value): void
    {
        $this->signedIn();
        $product = $this->createMock(Product::class);
        $defaults = [
            'getStatus' => 1,
            'isVisibleInSiteVisibility' => true,
            'getWebsiteIds' => ['1'],
            'getSku' => 'SKU-1',
        ];
        $defaults[$method] = $value;
        foreach ($defaults as $name => $return) {
            $product->method($name)->willReturn($return);
        }
        $this->repository->method('getById')->willReturn($product);
        $this->provider->expects($this->never())->method('get');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }

    public function testAConfigurableProductIsNotAskedForAPriceOrALadder(): void
    {
        $this->signedIn();
        $this->product->method('getTypeId')->willReturn('configurable');
        $this->repository->method('getById')->willReturn($this->product);
        $this->provider->expects($this->once())->method('get')
            ->with(1, 42, 'buyer@example.test', 'SKU-1', 'USD', [Config::B2B_ORDER_LIMITS, Config::B2B_BUYER_GROUP])
            ->willReturn(DataProvider::EMPTY_RESULT);

        $this->controller()->execute();
    }

    public function testAMissingProductIdIsNotLookedUp(): void
    {
        $this->signedIn();
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(static function ($name, $default = null) {
            return $default;
        });
        $this->repository->expects($this->never())->method('getById');

        $this->controller()->execute();

        self::assertSame(Presenter::EMPTY_PAYLOAD, $this->answered);
    }
}
