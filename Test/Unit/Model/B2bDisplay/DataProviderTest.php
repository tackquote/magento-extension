<?php
/**
 * Which routes are asked, with what, and what is cached for whom.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\B2bDisplay;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\B2bDisplay\DataProvider;
use TackQuote\Quotes\Model\B2bDisplay\ResponseMapper;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\B2bDisplay\DataProvider
 */
class DataProviderTest extends TestCase
{
    private const EMAIL = 'Buyer@Example.test';

    /** @var Config&MockObject */
    private $config;

    /** @var StorefrontClient&MockObject */
    private $client;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var array<string, string> In-memory cache. */
    private $store = [];

    /** @var array<int, array{string, array}> Every call made: [route, query]. */
    private $calls = [];

    /** @var array<string, array> Canned answer per route. */
    private $answers = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->client = $this->createMock(StorefrontClient::class);
        $this->client->method('get')->willReturnCallback(function ($route, $query) {
            $this->calls[] = [$route, $query];

            return $this->answers[$route] ?? ['ok' => false, 'status' => 500];
        });

        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(function ($id) {
            return $this->store[$id] ?? false;
        });
        $this->cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->store[$id] = (string) $data;

            return true;
        });

        $this->answers = [
            StorefrontClient::ROUTE_WHOLESALE_PRICE => ['ok' => true, 'status' => 200, 'data' => [
                'status' => 'priced', 'unitPrice' => 9, 'currency' => 'USD', 'quantity' => 1,
                'accountSpecific' => true, 'checkoutApplied' => false,
            ]],
            StorefrontClient::ROUTE_QUANTITY_BREAKS => ['ok' => true, 'status' => 200, 'data' => [
                'status' => 'priced', 'currency' => 'USD', 'accountSpecific' => true,
                'rows' => [['minQty' => 1, 'unitPrice' => 9], ['minQty' => 10, 'unitPrice' => 8]],
            ]],
            StorefrontClient::ROUTE_ORDER_LIMITS => ['ok' => true, 'status' => 200, 'data' => [
                'status' => 'limited', 'accountSpecific' => true,
                'limits' => [['limitType' => 'product_qty', 'min' => 2, 'max' => 20]],
            ]],
            StorefrontClient::ROUTE_BUYER_GROUP => ['ok' => true, 'status' => 200, 'data' => [
                'status' => 'grouped', 'name' => 'Tier 2', 'code' => 'T2',
            ]],
        ];
    }

    /**
     * @param string[] $features
     * @return DataProvider
     */
    private function provider(array $features = Config::B2B_FEATURES): DataProvider
    {
        $this->config->method('getEnabledB2bFeatures')->willReturn($features);

        return new DataProvider($this->config, $this->client, new ResponseMapper(), $this->cache, new Json());
    }

    public function testEachSwitchedOnFeatureAsksItsRouteWithTheSignedInBuyer(): void
    {
        $result = $this->provider()->get(1, 42, self::EMAIL, ' SKU-1 ', 'usd');

        $byRoute = [];
        foreach ($this->calls as [$route, $query]) {
            $byRoute[$route] = $query;
        }
        self::assertSame(
            ['buyerEmail' => 'buyer@example.test', 'sku' => 'SKU-1', 'currency' => 'USD', 'quantity' => '1'],
            $byRoute[StorefrontClient::ROUTE_WHOLESALE_PRICE]
        );
        self::assertSame(
            ['buyerEmail' => 'buyer@example.test', 'sku' => 'SKU-1', 'currency' => 'USD'],
            $byRoute[StorefrontClient::ROUTE_QUANTITY_BREAKS]
        );
        self::assertSame(
            ['buyerEmail' => 'buyer@example.test', 'sku' => 'SKU-1'],
            $byRoute[StorefrontClient::ROUTE_ORDER_LIMITS]
        );
        self::assertSame(['buyerEmail' => 'buyer@example.test'], $byRoute[StorefrontClient::ROUTE_BUYER_GROUP]);

        self::assertSame(['unitPrice' => 9.0, 'currency' => 'USD'], $result['accountPrice']);
        self::assertCount(2, $result['quantityBreaks']['rows']);
        self::assertSame(['min' => 2, 'max' => 20, 'accountSpecific' => true], $result['orderLimit']);
        self::assertSame(['name' => 'Tier 2'], $result['buyerGroup']);
    }

    public function testASwitchedOffFeatureIsNeverAsked(): void
    {
        $result = $this->provider([Config::B2B_BUYER_GROUP])->get(1, 42, self::EMAIL, 'SKU-1', 'USD');

        self::assertSame([StorefrontClient::ROUTE_BUYER_GROUP], array_column($this->calls, 0));
        self::assertNull($result['accountPrice']);
        self::assertNull($result['quantityBreaks']);
        self::assertNull($result['orderLimit']);
    }

    public function testOnlyNarrowsButNeverWidensTheSwitchedOnSet(): void
    {
        $this->provider([Config::B2B_ACCOUNT_PRICE, Config::B2B_ORDER_LIMITS])
            ->get(1, 42, self::EMAIL, 'SKU-1', 'USD', [Config::B2B_ORDER_LIMITS, Config::B2B_BUYER_GROUP]);

        self::assertSame([StorefrontClient::ROUTE_ORDER_LIMITS], array_column($this->calls, 0));
    }

    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function nobodyToAskAbout(): array
    {
        return [
            'no customer id' => [0, self::EMAIL, 'SKU-1', 'USD'],
            'no email' => [42, '  ', 'SKU-1', 'USD'],
            'no sku' => [42, self::EMAIL, '', 'USD'],
            'no currency' => [42, self::EMAIL, 'SKU-1', ''],
        ];
    }

    /**
     * @dataProvider nobodyToAskAbout
     */
    public function testNothingIsAskedWithoutACustomerProductAndCurrency(
        int $id,
        string $email,
        string $sku,
        string $cur
    ): void {
        $result = $this->provider()->get(1, $id, $email, $sku, $cur);

        self::assertSame([], $this->calls);
        self::assertSame(DataProvider::EMPTY_RESULT, $result);
    }

    public function testNothingIsAskedWhenNoFeatureIsOn(): void
    {
        self::assertSame(DataProvider::EMPTY_RESULT, $this->provider([])->get(1, 42, self::EMAIL, 'SKU-1', 'USD'));
        self::assertSame([], $this->calls);
    }

    public function testACompleteAnswerIsServedFromCacheForTheSameCustomerAndProduct(): void
    {
        $provider = $this->provider();
        $first = $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');
        $calls = count($this->calls);

        $second = $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');

        self::assertSame($calls, count($this->calls), 'the second view makes no request');
        self::assertSame($first, $second);
    }

    public function testTheCacheKeyVariesByEveryPartThatChangesTheAnswer(): void
    {
        $provider = $this->provider();
        $base = $provider->cacheKey(1, 42, self::EMAIL, 'SKU-1', 'USD', Config::B2B_FEATURES);

        $variants = [
            'store' => $provider->cacheKey(2, 42, self::EMAIL, 'SKU-1', 'USD', Config::B2B_FEATURES),
            'customer' => $provider->cacheKey(1, 43, self::EMAIL, 'SKU-1', 'USD', Config::B2B_FEATURES),
            'email' => $provider->cacheKey(1, 42, 'other@example.test', 'SKU-1', 'USD', Config::B2B_FEATURES),
            'sku' => $provider->cacheKey(1, 42, self::EMAIL, 'SKU-2', 'USD', Config::B2B_FEATURES),
            'currency' => $provider->cacheKey(1, 42, self::EMAIL, 'SKU-1', 'EUR', Config::B2B_FEATURES),
            'features' => $provider->cacheKey(1, 42, self::EMAIL, 'SKU-1', 'USD', [Config::B2B_BUYER_GROUP]),
        ];
        foreach ($variants as $part => $key) {
            self::assertNotSame($base, $key, "the cache key must vary by $part");
        }

        self::assertSame(
            $base,
            $provider->cacheKey(1, 42, 'buyer@example.test', 'SKU-1', 'usd', Config::B2B_FEATURES),
            'case of the email and currency is not a different customer'
        );
    }

    public function testNoEmailAddressAppearsInAnyCacheId(): void
    {
        $this->provider()->get(1, 42, self::EMAIL, 'SKU-1', 'USD');

        self::assertNotEmpty($this->store);
        foreach (array_keys($this->store) as $id) {
            self::assertStringNotContainsStringIgnoringCase('example.test', $id);
            self::assertStringNotContainsStringIgnoringCase('buyer', $id);
        }
    }

    public function testAnotherCustomerNeverReadsTheFirstCustomersEntry(): void
    {
        $provider = $this->provider();
        $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');
        $this->answers[StorefrontClient::ROUTE_BUYER_GROUP]['data']['name'] = 'Retail';
        $before = count($this->calls);

        $other = $provider->get(1, 77, 'second@example.test', 'SKU-1', 'USD');

        self::assertGreaterThan($before, count($this->calls), 'the second customer is asked about afresh');
        self::assertSame(['name' => 'Retail'], $other['buyerGroup']);
    }

    public function testAFailedRouteContributesNothingAndIsNotCachedAsComplete(): void
    {
        $this->answers[StorefrontClient::ROUTE_WHOLESALE_PRICE] = ['ok' => false, 'status' => 503];
        $provider = $this->provider();

        $result = $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');

        self::assertNull($result['accountPrice'], 'fail closed: no price is invented');
        self::assertSame(['name' => 'Tier 2'], $result['buyerGroup'], 'the routes that answered still count');
        $key = $provider->cacheKey(1, 42, self::EMAIL, 'SKU-1', 'USD', Config::B2B_FEATURES);
        self::assertArrayNotHasKey($key, $this->store, 'a partial answer is not cached as the answer');

        $calls = count($this->calls);
        self::assertSame(DataProvider::EMPTY_RESULT, $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD'));
        self::assertSame($calls, count($this->calls), 'the failed lookup backs off instead of re-asking');
    }

    public function testA429PausesTheWholeStore(): void
    {
        $this->answers[StorefrontClient::ROUTE_WHOLESALE_PRICE] = ['ok' => false, 'status' => 429];
        $provider = $this->provider();

        $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');
        self::assertSame([StorefrontClient::ROUTE_WHOLESALE_PRICE], array_column($this->calls, 0), 'stops at once');

        $calls = count($this->calls);
        $other = $provider->get(1, 77, 'second@example.test', 'SKU-9', 'USD');

        self::assertSame($calls, count($this->calls), 'another customer and product are not asked either');
        self::assertSame(DataProvider::EMPTY_RESULT, $other);
    }

    public function testOneCustomerCannotSpendTheStoresBudget(): void
    {
        $provider = $this->provider([Config::B2B_BUYER_GROUP]);
        for ($i = 0; $i < DataProvider::CUSTOMER_LOOKUP_BUDGET; $i++) {
            $provider->get(1, 42, self::EMAIL, 'SKU-' . $i, 'USD');
        }
        $calls = count($this->calls);

        $result = $provider->get(1, 42, self::EMAIL, 'SKU-over', 'USD');

        self::assertSame($calls, count($this->calls));
        self::assertSame(DataProvider::EMPTY_RESULT, $result);
    }

    public function testAnUnreadableCacheEntryIsIgnoredNotTrusted(): void
    {
        $provider = $this->provider([Config::B2B_BUYER_GROUP]);
        $key = $provider->cacheKey(1, 42, self::EMAIL, 'SKU-1', 'USD', [Config::B2B_BUYER_GROUP]);
        $this->store[$key] = '{"accountPrice":{"unitPrice":0.01}}';

        $result = $provider->get(1, 42, self::EMAIL, 'SKU-1', 'USD');

        self::assertNotEmpty($this->calls, 'a malformed entry is refetched');
        self::assertNull($result['accountPrice']);
    }
}
