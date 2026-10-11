<?php
/**
 * One batched request per cart, the currency gate, the cache, the fresh path, and failure.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;
use TackQuote\Quotes\Model\Enforce\PriceResolver;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\PriceResolver
 */
class PriceResolverTest extends TestCase
{
    private const BUYER = ['storeId' => 2, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD'];

    /** @var StorefrontClient&MockObject */
    private $client;

    /** @var array<string, string> */
    public $cacheData = [];

    /** @var array<string, int|null> */
    public $cacheTtl = [];

    /** @var array<int, array> resolvePricing() calls: [lines, email, storeId] */
    private $resolveCalls = [];

    /** @var array<int, array> get() calls: [route, query, storeId] */
    private $getCalls = [];

    /** @var array|callable */
    private $resolveAnswer;

    /** @var array */
    private $currencyAnswer;

    protected function setUp(): void
    {
        $this->resolveAnswer = self::ok([['sku' => 'A', 'quantity' => 2, 'unitPrice' => 7.5, 'source' => 'price_book']]);
        $this->currencyAnswer = ['ok' => true, 'status' => 200, 'data' => ['status' => 'priced', 'currency' => 'USD', 'unitPrice' => 7.5]];

        $this->client = $this->createMock(StorefrontClient::class);
        $this->client->method('resolvePricing')->willReturnCallback(function (array $lines, string $email, $storeId) {
            $this->resolveCalls[] = [$lines, $email, $storeId];

            return is_callable($this->resolveAnswer) ? ($this->resolveAnswer)($lines) : $this->resolveAnswer;
        });
        $this->client->method('get')->willReturnCallback(function (string $route, array $query, $storeId) {
            $this->getCalls[] = [$route, $query, $storeId];

            return $this->currencyAnswer;
        });
    }

    private static function ok(array $items, bool $matched = true): array
    {
        return ['ok' => true, 'status' => 201, 'data' => ['buyerMatched' => $matched, 'items' => $items]];
    }

    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) {
            return $this->cacheData[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null) {
            $this->cacheData[$id] = (string) $data;
            $this->cacheTtl[$id] = $ttl;

            return true;
        });

        return $cache;
    }

    private function resolver(): PriceResolver
    {
        return new PriceResolver($this->client, new AnswerMapper(), $this->cache(), new Json());
    }

    public function testEveryLineGoesOutInOneRequestAtItsOwnQuantityAndDuplicatesOnce(): void
    {
        $this->resolver()->resolve(self::BUYER, [
            ['sku' => 'B', 'quantity' => 1],
            ['sku' => 'A', 'quantity' => 2],
            ['sku' => 'A', 'quantity' => 2],
            ['sku' => 'A', 'quantity' => 10],
        ]);

        self::assertCount(1, $this->resolveCalls, 'one request for the whole cart');
        self::assertSame(
            [['sku' => 'A', 'quantity' => 10], ['sku' => 'A', 'quantity' => 2], ['sku' => 'B', 'quantity' => 1]],
            $this->resolveCalls[0][0]
        );
        self::assertSame('buyer@example.test', $this->resolveCalls[0][1]);
        self::assertSame(2, $this->resolveCalls[0][2]);
    }

    public function testAConfirmedPriceIsReturnedUnderItsLineKey(): void
    {
        $result = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [AnswerMapper::lineKey('A', 2) => 7.5], 'fallback' => false], $result);
        self::assertSame(StorefrontClient::ROUTE_WHOLESALE_PRICE, $this->getCalls[0][0]);
        self::assertSame(
            ['buyerEmail' => 'buyer@example.test', 'sku' => 'A', 'quantity' => '2', 'currency' => 'USD'],
            $this->getCalls[0][1],
            'the currency read is asked in the CART currency, at the line\'s quantity'
        );
    }

    public function testAnAccountPricedInAnotherCurrencyChargesNothingAndIsNotAFailure(): void
    {
        $this->currencyAnswer = ['ok' => true, 'status' => 200, 'data' => [
            'status' => 'unpriced', 'reason' => 'currency_mismatch', 'currency' => 'EUR', 'presentmentCurrency' => 'USD',
        ]];

        $result = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [], 'fallback' => false], $result);
    }

    public function testACurrencyThatCannotBeConfirmedChargesNothingAndIsAFallback(): void
    {
        $this->currencyAnswer = ['ok' => false, 'status' => 0];

        $resolver = $this->resolver();
        $result = $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [], 'fallback' => true], $result);
        $key = $resolver->cacheKey(self::BUYER, [AnswerMapper::lineKey('A', 2)]);
        self::assertArrayNotHasKey($key, $this->cacheData, 'a failure is never cached as an answer');
        self::assertArrayHasKey($key . '_failed', $this->cacheData);
    }

    public function testNoChargeablePriceMeansNoCurrencyRead(): void
    {
        $this->resolveAnswer = self::ok([['sku' => 'A', 'quantity' => 2, 'unitPrice' => 7.5, 'source' => 'catalog']]);

        $result = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [], 'fallback' => false], $result);
        self::assertSame([], $this->getCalls);
    }

    public function testALineTheCartDidNotAskForIsNeverReturned(): void
    {
        $this->resolveAnswer = self::ok([
            ['sku' => 'A', 'quantity' => 2, 'unitPrice' => 7.5, 'source' => 'price_book'],
            ['sku' => 'Z', 'quantity' => 1, 'unitPrice' => 0.01, 'source' => 'price_book'],
        ]);

        $result = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame([AnswerMapper::lineKey('A', 2) => 7.5], $result['prices']);
    }

    public function testAFailedResolveIsAFallbackAndBacksOff(): void
    {
        $this->resolveAnswer = ['ok' => false, 'status' => 503];
        $resolver = $this->resolver();

        $first = $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);
        $this->resolveCalls = [];
        $again = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [], 'fallback' => true], $first);
        self::assertSame(['prices' => [], 'fallback' => true], $again);
        self::assertSame([], $this->resolveCalls, 'the per-cart back-off holds a second request off');
        $key = $resolver->cacheKey(self::BUYER, [AnswerMapper::lineKey('A', 2)]);
        self::assertArrayNotHasKey($key, $this->cacheData, 'a failure is never cached as an answer');
        self::assertSame(PriceResolver::FAILURE_BACKOFF_TTL, $this->cacheTtl[$key . '_failed']);
    }

    public function testA429PausesTheWholeStore(): void
    {
        $this->resolveAnswer = ['ok' => false, 'status' => 429];
        $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);
        $this->resolveCalls = [];

        $other = ['storeId' => 2, 'customerId' => 99, 'email' => 'other@example.test', 'currency' => 'USD'];
        $result = $this->resolver()->resolve($other, [['sku' => 'Q', 'quantity' => 1]]);

        self::assertSame(['prices' => [], 'fallback' => true], $result);
        self::assertSame([], $this->resolveCalls);
    }

    public function testACompleteAnswerIsCachedForTheSameCustomerAndLines(): void
    {
        $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);
        $this->resolveCalls = [];
        $this->getCalls = [];

        $result = $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertSame(['prices' => [AnswerMapper::lineKey('A', 2) => 7.5], 'fallback' => false], $result);
        self::assertSame([], $this->resolveCalls, 'served from the cache');
        $key = $this->resolver()->cacheKey(self::BUYER, [AnswerMapper::lineKey('A', 2)]);
        self::assertSame(PriceResolver::CACHE_TTL, $this->cacheTtl[$key]);
    }

    public function testFreshIgnoresAStaleCachedPriceAndReplacesIt(): void
    {
        $resolver = $this->resolver();
        $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        // TackQuote's price changed; the cache still holds 7.5.
        $this->resolveAnswer = self::ok([['sku' => 'A', 'quantity' => 2, 'unitPrice' => 9.0, 'source' => 'price_book']]);
        $this->resolveCalls = [];
        $this->getCalls = [];

        $fresh = $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]], true);

        self::assertSame([AnswerMapper::lineKey('A', 2) => 9.0], $fresh['prices']);
        self::assertCount(1, $this->resolveCalls, 'TackQuote is asked again');
        self::assertCount(1, $this->getCalls, 'and the currency too, not from cache');

        // The memo and the cache now hold the fresh answer, so the totals collection the submit
        // triggers sees the same price.
        $this->resolveCalls = [];
        self::assertSame($fresh, $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]));
        self::assertSame($fresh, $this->resolver()->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]));
        self::assertSame([], $this->resolveCalls);
    }

    public function testFreshIgnoresTheBackOffAndAFreshFailureNeverServesTheOldPrice(): void
    {
        $resolver = $this->resolver();
        $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);
        $this->resolveAnswer = ['ok' => false, 'status' => 0];

        $fresh = $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]], true);

        self::assertSame(['prices' => [], 'fallback' => true], $fresh);
        self::assertSame($fresh, $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]));
    }

    public function testMoreThanFiftyLinesAsksForFiftyAndReportsAFallback(): void
    {
        $lines = [];
        for ($i = 0; $i < 51; $i++) {
            $lines[] = ['sku' => sprintf('S%02d', $i), 'quantity' => 1];
        }
        $this->resolveAnswer = static function (array $asked): array {
            $items = [];
            foreach ($asked as $line) {
                $items[] = $line + ['unitPrice' => 1.0, 'source' => 'price_book'];
            }

            return self::ok($items);
        };

        $result = $this->resolver()->resolve(self::BUYER, $lines);

        self::assertCount(StorefrontClient::RESOLVE_MAX_ITEMS, $this->resolveCalls[0][0]);
        self::assertCount(50, $result['prices']);
        self::assertArrayNotHasKey(AnswerMapper::lineKey('S50', 1), $result['prices']);
        self::assertTrue($result['fallback']);
    }

    public function testTheCacheKeyVariesWithEveryPart(): void
    {
        $resolver = $this->resolver();
        $base = $resolver->cacheKey(self::BUYER, ['A' . "\n" . '2']);
        $variants = [
            $resolver->cacheKey(['storeId' => 3] + self::BUYER, ['A' . "\n" . '2']),
            $resolver->cacheKey(['customerId' => 8] + self::BUYER, ['A' . "\n" . '2']),
            $resolver->cacheKey(['email' => 'x@example.test'] + self::BUYER, ['A' . "\n" . '2']),
            $resolver->cacheKey(['currency' => 'EUR'] + self::BUYER, ['A' . "\n" . '2']),
            $resolver->cacheKey(self::BUYER, ['A' . "\n" . '3']),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base, $variant);
        }
        self::assertStringNotContainsString('buyer@example.test', $base);
    }

    public function testADamagedCacheEntryIsRefetchedNotCharged(): void
    {
        $resolver = $this->resolver();
        $key = $resolver->cacheKey(self::BUYER, [AnswerMapper::lineKey('A', 2)]);
        $this->cacheData[$key] = '{"prices":{"A\n2":"0"},"fallback":false}';

        $resolver->resolve(self::BUYER, [['sku' => 'A', 'quantity' => 2]]);

        self::assertCount(1, $this->resolveCalls);
    }
}
