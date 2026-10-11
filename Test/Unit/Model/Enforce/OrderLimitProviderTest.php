<?php
/**
 * The order-limits read the cart enforces: the same request as the product-page notice, cached
 * per customer and SKU, and failing open.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Enforce\OrderLimitProvider;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\OrderLimitProvider
 */
class OrderLimitProviderTest extends TestCase
{
    private const BUYER = ['storeId' => 2, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD'];

    /** @var array<string, string> */
    private $cacheData = [];

    /** @var array<int, array> */
    private $calls = [];

    /** @var string[] WARNING lines */
    private $warnings = [];

    /** @var array */
    private $answer = ['ok' => true, 'status' => 200, 'data' => ['status' => 'limited', 'limits' => []]];

    private function provider(): OrderLimitProvider
    {
        $client = $this->createMock(StorefrontClient::class);
        $client->method('get')->willReturnCallback(function (string $route, array $query, $storeId) {
            $this->calls[] = [$route, $query, $storeId];

            return $this->answer;
        });
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) {
            return $this->cacheData[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cacheData[$id] = (string) $data;

            return true;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string) $message;
        });

        return new OrderLimitProvider($client, $cache, new Json(), $logger);
    }

    public function testItAsksTheNoticesRouteWithTheNoticesParameters(): void
    {
        $data = $this->provider()->forSku(self::BUYER, ' A-1 ');

        self::assertSame(['status' => 'limited', 'limits' => []], $data);
        self::assertSame(
            [[StorefrontClient::ROUTE_ORDER_LIMITS, ['buyerEmail' => 'buyer@example.test', 'sku' => 'A-1'], 2]],
            $this->calls
        );
    }

    public function testAnAnswerIsCachedForTheSameCustomerAndSku(): void
    {
        $this->provider()->forSku(self::BUYER, 'A-1');
        $this->provider()->forSku(self::BUYER, 'A-1');

        self::assertCount(1, $this->calls);
        $this->provider()->forSku(['customerId' => 8] + self::BUYER, 'A-1');
        self::assertCount(2, $this->calls, 'another customer is asked about separately');
    }

    public function testAFailureReturnsNothingAndBacksOff(): void
    {
        $this->answer = ['ok' => false, 'status' => 429];

        self::assertNull($this->provider()->forSku(self::BUYER, 'A-1'));
        self::assertNull($this->provider()->forSku(['customerId' => 9] + self::BUYER, 'B-2'));
        self::assertCount(1, $this->calls, 'a 429 pauses the store, shared with the product page');
        self::assertArrayHasKey('tackquote_b2b_throttled_2', $this->cacheData);
    }

    public function testAColdCacheAsksTackQuoteBeforeAnswering(): void
    {
        $this->answer = ['ok' => true, 'status' => 200, 'data' => ['status' => 'limited', 'limits' => [['min' => 2]]]];

        $data = $this->provider()->forSku(self::BUYER, 'A-1');

        self::assertSame(['status' => 'limited', 'limits' => [['min' => 2]]], $data);
        self::assertCount(1, $this->calls, 'an empty cache is a synchronous lookup, never "no limits"');
        self::assertSame([], $this->warnings);
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'unreachable or no key' => [['ok' => false, 'status' => 0], 'could not be reached'],
            'server error' => [['ok' => false, 'status' => 503], 'HTTP 503'],
            'throttled' => [['ok' => false, 'status' => 429], 'HTTP 429'],
            'not an answer' => [['ok' => false, 'status' => 200], 'HTTP 200 with a body'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testEveryFailureThatRefusesNothingIsLoggedWithTheSku(array $answer, string $reason): void
    {
        $this->answer = $answer;

        self::assertNull($this->provider()->forSku(self::BUYER, 'A-1'));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('SKU "A-1"', $this->warnings[0]);
        self::assertStringContainsString('nothing was refused', $this->warnings[0]);
        self::assertStringContainsString($reason, $this->warnings[0]);
        self::assertStringNotContainsString('buyer@example.test', $this->warnings[0]);
    }

    public function testABackedOffLookupIsLoggedToo(): void
    {
        $this->answer = ['ok' => false, 'status' => 503];
        $this->provider()->forSku(self::BUYER, 'A-1');
        $this->provider()->forSku(self::BUYER, 'A-1');

        self::assertCount(1, $this->calls);
        self::assertCount(2, $this->warnings);
        self::assertStringContainsString('failed less than 30 seconds ago', $this->warnings[1]);

        $this->answer = ['ok' => false, 'status' => 429];
        $this->provider()->forSku(self::BUYER, 'B-2');
        $this->provider()->forSku(self::BUYER, 'C-3');
        self::assertStringContainsString('SKU "C-3"', $this->warnings[3]);
        self::assertStringContainsString('pausing its lookups', $this->warnings[3]);
    }

    public function testTheCacheIdCarriesNoEmail(): void
    {
        self::assertStringNotContainsString('buyer@example.test', $this->provider()->cacheKey(self::BUYER, 'A-1'));
    }
}
