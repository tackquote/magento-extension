<?php
/**
 * Devstore Q/A finding #39, end to end: the first add to a brand-new cart, right after a cache
 * flush, is checked like every other add.
 *
 * Wires the real add guard (Plugin\Quote\OrderLimitCartGuard), checker, eligibility and provider.
 * Only Magento's edges are doubles: the visitor, the config switch, an EMPTY cache (cold) and the
 * HTTP client. The quote is the one Checkout\Model\Session::getQuote() hands a signed-in customer
 * who has no active cart: customer and store set, never saved, so no quote_currency_code yet
 * (Magento sets it in Quote::beforeSave()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;
use TackQuote\Quotes\Model\B2bDisplay\ResponseMapper;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;
use TackQuote\Quotes\Model\Enforce\Eligibility;
use TackQuote\Quotes\Model\Enforce\OrderLimitChecker;
use TackQuote\Quotes\Model\Enforce\OrderLimitProvider;
use TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;
use TackQuote\Quotes\Plugin\Quote\OrderLimitCartGuard;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\Eligibility
 * @covers \TackQuote\Quotes\Model\Enforce\OrderLimitChecker
 * @covers \TackQuote\Quotes\Model\Enforce\OrderLimitProvider
 */
class OrderLimitNewCartTest extends TestCase
{
    /** @var array StorefrontClient::get() result */
    private $answer = [
        'ok' => true,
        'status' => 200,
        'data' => [
            'status' => 'limited',
            'accountSpecific' => true,
            'limits' => [[
                'limitType' => 'product_qty',
                'sku' => 'BAG-1',
                'min' => 2,
                'max' => 10,
                'currency' => null,
                'message' => null,
            ]],
        ],
    ];

    /** @var int */
    private $lookups = 0;

    /** @var string[] */
    private $warnings = [];

    private function guard(): OrderLimitCartGuard
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnforceOrderLimitsEnabled')->willReturn(true);
        $config->method('getB2bCustomerGroups')->willReturn('');

        $visitor = $this->createMock(VisitorContextInterface::class);
        $visitor->method('isExempt')->willReturn(false);
        $visitor->method('isLoggedIn')->willReturn(true);
        $visitor->method('getCustomerId')->willReturn(7);
        $visitor->method('getCustomerGroupId')->willReturn(1);
        $visitor->method('getCustomerEmail')->willReturn('buyer@example.test');

        $client = $this->createMock(StorefrontClient::class);
        $client->method('get')->willReturnCallback(function () {
            $this->lookups++;

            return $this->answer;
        });
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string) $message;
        });

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $checker = new OrderLimitChecker(
            $config,
            new Eligibility($config, $visitor),
            new OrderLimitProvider($client, $cache, new Json(), $logger),
            new ResponseMapper(),
            new AnswerMapper(),
            new Presenter(
                $this->createMock(CatalogHelper::class),
                $priceCurrency,
                $this->createMock(PriceEnforcementSwitch::class)
            ),
            $priceCurrency
        );

        return new OrderLimitCartGuard($checker, $this->createMock(QuotedCart::class));
    }

    /**
     * The quote Checkout\Model\Session::getQuote() builds for a signed-in customer with no cart.
     */
    private function newUnsavedQuote(): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'getStoreId', 'getStore'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getStore')->willReturn($store);
        $quote->setData('customer_id', 7);

        return $quote;
    }

    private function product(): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTypeId', 'getSku', 'getName'])
            ->getMock();
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getSku')->willReturn('BAG-1');
        $product->method('getName')->willReturn('Bag');

        return $product;
    }

    private function refusal(Quote $quote, float $qty): ?string
    {
        try {
            $this->guard()->beforeAddProduct($quote, $this->product(), new DataObject(['qty' => $qty]));
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testTheFirstAddToABrandNewCartIsRefusedAboveTheMaximum(): void
    {
        $quote = $this->newUnsavedQuote();
        self::assertSame('', (string) $quote->getQuoteCurrencyCode(), 'precondition: Magento has not saved it');

        $message = $this->refusal($quote, 12);

        self::assertNotNull($message, 'qty 12 against a limit of 2 to 10 must not reach an empty cart');
        self::assertStringContainsString('Bag:', $message);
        self::assertStringContainsString('You tried to add 12.', $message);
        self::assertSame(1, $this->lookups, 'the cold cache was filled by a synchronous lookup');
        self::assertSame([], $this->warnings);
    }

    public function testTheFirstAddToABrandNewCartWithinTheLimitIsAccepted(): void
    {
        self::assertNull($this->refusal($this->newUnsavedQuote(), 4));
        self::assertSame(1, $this->lookups);
    }

    public function testOnlyAGenuineFailureLetsTheAddThroughAndItIsLogged(): void
    {
        $this->answer = ['ok' => false, 'status' => 503];

        self::assertNull($this->refusal($this->newUnsavedQuote(), 12));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('SKU "BAG-1"', $this->warnings[0]);
        self::assertStringContainsString('HTTP 503', $this->warnings[0]);
    }
}
