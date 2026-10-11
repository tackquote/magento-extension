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

        return new OrderLimitProvider($client, $cache, new Json());
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

    public function testTheCacheIdCarriesNoEmail(): void
    {
        self::assertStringNotContainsString('buyer@example.test', $this->provider()->cacheKey(self::BUYER, 'A-1'));
    }
}
