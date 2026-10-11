<?php
/**
 * The price-access read behind "everyone except approved wholesale accounts": the call that
 * goes out, the 5-minute cache, and FAIL CLOSED on everything that is not a definite yes.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteOnly;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\QuoteOnly\WholesaleApproval;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnly\WholesaleApproval
 */
class WholesaleApprovalTest extends TestCase
{
    private const EMAIL = 'buyer@example.test';

    /** @var StorefrontClient&MockObject */
    private $client;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var array<string, array{0: string, 1: array, 2: int}> What was cached: id => [value, tags, ttl]. */
    private $saved = [];

    /** @var array<string, string> */
    private $store = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(StorefrontClient::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(function ($id) {
            return $this->store[$id] ?? false;
        });
        $this->cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $ttl = null) {
            $this->saved[$id] = [$data, $tags, $ttl];
            $this->store[$id] = $data;

            return true;
        });
    }

    private function approval(): WholesaleApproval
    {
        return new WholesaleApproval($this->client, $this->cache);
    }

    private function answer(array $result): void
    {
        $this->client->method('get')->willReturn($result);
    }

    public function testItAsksPriceAccessWithTheLowerCasedEmailAndTheStore(): void
    {
        $this->client->expects(self::once())->method('get')
            ->with(StorefrontClient::ROUTE_PRICE_ACCESS, ['buyerEmail' => self::EMAIL], 3)
            ->willReturn(['ok' => true, 'status' => 200, 'data' => ['status' => 'linked', 'wholesaleApproved' => true]]);

        self::assertTrue($this->approval()->isApproved('  Buyer@Example.TEST ', 42, 3));
    }

    public function testAnApprovedAnswerIsCachedForFiveMinutes(): void
    {
        $this->answer(['ok' => true, 'status' => 200, 'data' => ['status' => 'linked', 'wholesaleApproved' => true]]);

        self::assertTrue($this->approval()->isApproved(self::EMAIL, 42, 1));

        $entry = $this->saved[WholesaleApproval::cacheId(self::EMAIL, 42, 1)] ?? null;
        self::assertNotNull($entry, 'the answer was not cached');
        self::assertSame(300, $entry[2], 'the WooCommerce plugin TTL: five minutes');
        self::assertSame(WholesaleApproval::CACHE_TTL, $entry[2]);
        self::assertSame([WholesaleApproval::CACHE_TAG], $entry[1]);
    }

    public function testACachedAnswerIsUsedWithoutAskingAgain(): void
    {
        $this->answer(['ok' => true, 'status' => 200, 'data' => ['status' => 'linked', 'wholesaleApproved' => true]]);
        $this->approval()->isApproved(self::EMAIL, 42, 1);

        // A fresh instance (a new request) reads the shared cache.
        $client = $this->createMock(StorefrontClient::class);
        $client->expects(self::never())->method('get');

        self::assertTrue((new WholesaleApproval($client, $this->cache))->isApproved(self::EMAIL, 42, 1));
    }

    public function testLinkedButNotApprovedIsNotApprovalAndIsCachedAsADefiniteAnswer(): void
    {
        $this->answer(['ok' => true, 'status' => 200, 'data' => ['status' => 'linked', 'wholesaleApproved' => false]]);

        self::assertFalse($this->approval()->isApproved(self::EMAIL, 42, 1));
        self::assertSame(300, $this->saved[WholesaleApproval::cacheId(self::EMAIL, 42, 1)][2]);
    }

    public function testUnlinkedAndAnonymousAreNotApproval(): void
    {
        $this->client->method('get')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'status' => 200, 'data' => ['status' => 'unlinked', 'reason' => 'customer_not_linked']],
            ['ok' => true, 'status' => 200, 'data' => ['status' => 'anonymous']]
        );

        self::assertFalse($this->approval()->isApproved('a@example.test', 1, 1));
        self::assertFalse($this->approval()->isApproved('b@example.test', 2, 1));
    }

    /**
     * @dataProvider failures
     */
    public function testEveryFailureIsNotApprovedAndIsRememberedForOneMinute(array $result): void
    {
        $this->answer($result);

        self::assertFalse($this->approval()->isApproved(self::EMAIL, 42, 1), 'a failure must fail CLOSED');
        $entry = $this->saved[WholesaleApproval::cacheId(self::EMAIL, 42, 1)] ?? null;
        self::assertNotNull($entry, 'a failure must be remembered so an outage is not retried per page view');
        self::assertSame(60, $entry[2]);
        self::assertSame(WholesaleApproval::FAIL_TTL, $entry[2]);
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function failures(): array
    {
        return [
            'transport error' => [['ok' => false, 'status' => 0]],
            'throttled' => [['ok' => false, 'status' => 429]],
            'server error' => [['ok' => false, 'status' => 503]],
            'unauthorised key' => [['ok' => false, 'status' => 401]],
            'no status field' => [['ok' => true, 'status' => 200, 'data' => ['wholesaleApproved' => true]]],
            'no body' => [['ok' => true, 'status' => 200]],
        ];
    }

    public function testAFailureIsNotRetriedWithinItsBackOff(): void
    {
        $this->client->expects(self::once())->method('get')->willReturn(['ok' => false, 'status' => 503]);

        self::assertFalse($this->approval()->isApproved(self::EMAIL, 42, 1));
        self::assertFalse((new WholesaleApproval($this->client, $this->cache))->isApproved(self::EMAIL, 42, 1));
    }

    public function testNoEmailAsksNothing(): void
    {
        $this->client->expects(self::never())->method('get');

        self::assertFalse($this->approval()->isApproved('   ', 42, 1));
    }

    public function testTheCacheIdNeverContainsTheEmailAndSeparatesCustomersAndStores(): void
    {
        $id = WholesaleApproval::cacheId(self::EMAIL, 42, 1);

        self::assertStringNotContainsString('example', $id);
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $id, 'a valid Magento cache id');
        self::assertNotSame($id, WholesaleApproval::cacheId(self::EMAIL, 43, 1));
        self::assertNotSame($id, WholesaleApproval::cacheId(self::EMAIL, 42, 2));
        self::assertNotSame($id, WholesaleApproval::cacheId('other@example.test', 42, 1));
    }
}
