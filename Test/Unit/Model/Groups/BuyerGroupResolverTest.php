<?php
/**
 * The buyer-group read behind every rule: the call that goes out, the mapping of
 * BuyerGroupResult, the 60-second per-customer cache, and fail closed.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\BuyerGroupResolver;

/**
 * @covers \TackQuote\Quotes\Model\Groups\BuyerGroupResolver
 */
class BuyerGroupResolverTest extends TestCase
{
    private const EMAIL = 'buyer@example.test';

    /** @var StorefrontClient&MockObject */
    private $client;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var array<string, array{0: string, 1: array, 2: int}> */
    private $saved = [];

    /** @var array<string, string> */
    private $store = [];

    /** @var bool Whether the server namespaces Magento customer ids (`customerIdentity`). */
    private $namespaced = false;

    private const INSTALL_ID = '0b9f6c1e-3a52-4d0e-9a0f-5c1d2e3f4a5b';

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

    private function resolver(): BuyerGroupResolver
    {
        $identities = $this->createMock(IdentityResolver::class);
        $identities->method('forCustomer')->willReturnCallback(function ($id, $email) {
            return new AccountIdentity((int) $id, (string) $email, self::INSTALL_ID, $this->namespaced);
        });

        return new BuyerGroupResolver($this->client, $this->cache, new Json(), $identities);
    }

    private function answer(array $result): void
    {
        $this->client->method('get')->willReturn($result);
    }

    public function testItAsksBuyerGroupWithTheLowerCasedEmailAndTheStore(): void
    {
        $this->client->expects(self::once())->method('get')
            ->with(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL], 3, [])
            ->willReturn(['ok' => true, 'status' => 200, 'data' => ['status' => 'grouped', 'name' => 'Gold', 'code' => 'gold']]);

        $answer = $this->resolver()->resolve(3, 42, '  Buyer@Example.TEST ');

        self::assertSame(BuyerGroupAnswer::GROUPED, $answer->getStatus());
        self::assertSame('GOLD', $answer->getCode());
    }

    public function testWithCustomerIdentityTheMagentoCustomerIdAndInstallGoOut(): void
    {
        $this->namespaced = true;
        $this->client->expects(self::once())->method('get')
            ->with(
                StorefrontClient::ROUTE_BUYER_GROUP,
                ['buyerEmail' => self::EMAIL, 'buyerExternalId' => '42'],
                3,
                ['X-TackQuote-Platform' => 'magento', 'X-TackQuote-Store-Id' => self::INSTALL_ID]
            )
            ->willReturn(['ok' => true, 'status' => 200, 'data' => ['status' => 'none', 'taxExempt' => true]]);

        $answer = $this->resolver()->resolve(3, 42, self::EMAIL);

        self::assertTrue($answer->isTaxExempt());
        self::assertArrayHasKey(BuyerGroupResolver::cacheId(3, 42, self::EMAIL, true), $this->saved);
    }

    public function testAnEmailOnlyAnswerIsNotReusedForALinkedRead(): void
    {
        self::assertNotSame(
            BuyerGroupResolver::cacheId(3, 42, self::EMAIL, false),
            BuyerGroupResolver::cacheId(3, 42, self::EMAIL, true)
        );
    }

    public function testNoEmailOrNoCustomerAsksNothing(): void
    {
        $this->client->expects(self::never())->method('get');

        self::assertSame(BuyerGroupAnswer::ANONYMOUS, $this->resolver()->resolve(1, 42, '  ')->getStatus());
        self::assertSame(BuyerGroupAnswer::ANONYMOUS, $this->resolver()->resolve(1, 0, self::EMAIL)->getStatus());
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string, 3: bool}>
     */
    public static function answers(): array
    {
        return [
            'grouped' => [['status' => 'grouped', 'name' => 'G', 'code' => 'Tier2', 'taxExempt' => true], 'grouped', 'TIER2', true],
            'grouped, no code' => [['status' => 'grouped', 'name' => 'G', 'code' => ''], 'none', '', false],
            'none, exempt' => [['status' => 'none', 'taxExempt' => true], 'none', '', true],
            'none, exempt as a string' => [['status' => 'none', 'taxExempt' => 'true'], 'none', '', false],
            'none, exempt as 1' => [['status' => 'none', 'taxExempt' => 1], 'none', '', false],
            'unlinked' => [['status' => 'unlinked', 'reason' => 'customer_not_linked'], 'none', '', false],
            'anonymous' => [['status' => 'anonymous'], 'anonymous', '', false],
            'unknown status' => [['status' => 'maybe'], 'unavailable', '', false],
            'no status' => [['name' => 'G'], 'unavailable', '', false],
            'not an object' => ['grouped', 'unavailable', '', false],
        ];
    }

    /**
     * @dataProvider answers
     * @param mixed $data
     */
    public function testTheMapping($data, string $status, string $code, bool $taxExempt): void
    {
        $answer = BuyerGroupResolver::map($data);

        self::assertSame($status, $answer->getStatus());
        self::assertSame($code, $answer->getCode());
        self::assertSame($taxExempt, $answer->isTaxExempt());
    }

    public function testADefiniteAnswerIsCachedSixtySecondsUnderAHash(): void
    {
        $this->answer(['ok' => true, 'status' => 200, 'data' => ['status' => 'none']]);

        $this->resolver()->resolve(1, 42, self::EMAIL);

        $id = BuyerGroupResolver::cacheId(1, 42, self::EMAIL);
        self::assertStringNotContainsString('example', $id, 'no email address in a cache id');
        self::assertSame(60, $this->saved[$id][2] ?? null);
    }

    public function testTheCacheIdVariesByStoreCustomerAndEmail(): void
    {
        $id = BuyerGroupResolver::cacheId(1, 42, self::EMAIL);

        self::assertNotSame($id, BuyerGroupResolver::cacheId(2, 42, self::EMAIL));
        self::assertNotSame($id, BuyerGroupResolver::cacheId(1, 43, self::EMAIL));
        self::assertNotSame($id, BuyerGroupResolver::cacheId(1, 42, 'other@example.test'));
    }

    public function testACachedAnswerIsReusedWithoutAsking(): void
    {
        $this->client->expects(self::once())->method('get')
            ->willReturn(['ok' => true, 'status' => 200, 'data' => ['status' => 'grouped', 'name' => 'G', 'code' => 'GOLD', 'taxExempt' => true]]);

        $this->resolver()->resolve(1, 42, self::EMAIL);
        $again = $this->resolver()->resolve(1, 42, self::EMAIL);

        self::assertSame('GOLD', $again->getCode());
        self::assertTrue($again->isTaxExempt());
    }

    public function testAFailureIsUnavailableAndRememberedThirtySeconds(): void
    {
        $this->client->expects(self::once())->method('get')->willReturn(['ok' => false, 'status' => 503]);

        self::assertTrue($this->resolver()->resolve(1, 42, self::EMAIL)->isUnknown());
        self::assertTrue($this->resolver()->resolve(1, 42, self::EMAIL)->isUnknown());
        self::assertSame(30, $this->saved[BuyerGroupResolver::cacheId(1, 42, self::EMAIL)][2] ?? null);
    }

    public function testA429PausesTheWholeStore(): void
    {
        $this->client->expects(self::once())->method('get')->willReturn(['ok' => false, 'status' => 429]);

        self::assertTrue($this->resolver()->resolve(1, 42, self::EMAIL)->isUnknown());
        // Another customer of the same store is not asked either.
        self::assertTrue($this->resolver()->resolve(1, 99, 'other@example.test')->isUnknown());
    }

    public function testADamagedCacheEntryIsAskedAgain(): void
    {
        $this->store[BuyerGroupResolver::cacheId(1, 42, self::EMAIL)] = '{not json';
        $this->client->expects(self::once())->method('get')
            ->willReturn(['ok' => true, 'status' => 200, 'data' => ['status' => 'anonymous']]);

        self::assertSame(BuyerGroupAnswer::ANONYMOUS, $this->resolver()->resolve(1, 42, self::EMAIL)->getStatus());
    }
}
