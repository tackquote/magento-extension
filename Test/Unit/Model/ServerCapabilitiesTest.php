<?php
/**
 * The capability list: cached, never fetched from a render, failure is "nothing new".
 *
 * Mutations (applied, red, restored):
 *   S1  getCached() falls through to refresh() on a miss -> testGetCachedNeverAsksTheServer
 *   S2  a failed ping overwrites a cached list with [] -> testAFailedRefreshKeepsTheKnownList
 *   S3  a failed ping is not remembered -> testAFailedPingIsRememberedSoTheNextCheckDoesNotWait
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ServerCapabilities;

/**
 * @covers \TackQuote\Quotes\Model\ServerCapabilities
 */
class ServerCapabilitiesTest extends TestCase
{
    /** @var array<string, string> */
    private $store = [];

    /** @var Client&MockObject */
    private $client;

    protected function setUp(): void
    {
        $this->store = [];
        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCapabilities'])
            ->getMock();
    }

    private function subject(bool $configured = true): ServerCapabilities
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($key) {
            return $this->store[$key] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $key) {
            $this->store[$key] = (string) $data;

            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($key) {
            unset($this->store[$key]);

            return true;
        });

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured'])
            ->getMock();
        $config->method('isConfigured')->willReturn($configured);

        return new ServerCapabilities($this->client, $cache, new Json(), $config);
    }

    public function testGetCachedNeverAsksTheServer(): void
    {
        $this->client->expects(self::never())->method('getCapabilities');

        self::assertSame([], $this->subject()->getCached(1));
        self::assertFalse($this->subject()->supports(ServerCapabilities::ATTACHMENTS, 1, false));
    }

    public function testAFetchIsCachedAndAnsweredFromCacheAfterwards(): void
    {
        $this->client->expects(self::once())->method('getCapabilities')
            ->willReturn(['ok' => true, 'capabilities' => ['targetPrice', 'attachments', 7]]);
        $subject = $this->subject();

        self::assertTrue($subject->supports(ServerCapabilities::ATTACHMENTS, 1, true));
        self::assertSame(['targetPrice', 'attachments'], $subject->get(1));
        self::assertTrue($subject->supports(ServerCapabilities::TARGET_PRICE, 1, false));
    }

    public function testAnOlderServerListsNothing(): void
    {
        $this->client->method('getCapabilities')->willReturn(['ok' => true, 'capabilities' => []]);

        self::assertFalse($this->subject()->supports(ServerCapabilities::ATTACHMENTS, 1, true));
    }

    public function testAFailedPingIsRememberedSoTheNextCheckDoesNotWait(): void
    {
        $this->client->expects(self::once())->method('getCapabilities')->willReturn(['ok' => false]);
        $subject = $this->subject();

        self::assertSame([], $subject->get(1));
        self::assertSame([], $subject->get(1));
    }

    public function testAFailedRefreshKeepsTheKnownList(): void
    {
        $this->client->method('getCapabilities')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'capabilities' => ['attachments']],
            ['ok' => false]
        );
        $subject = $this->subject();
        $subject->refresh(1);

        self::assertSame(['attachments'], $subject->refresh(1));
        self::assertSame(['attachments'], $subject->getCached(1));
    }

    public function testForgetMakesTheNextCheckAskAgain(): void
    {
        $this->client->expects(self::exactly(2))->method('getCapabilities')
            ->willReturn(['ok' => true, 'capabilities' => ['attachments']]);
        $subject = $this->subject();
        $subject->get(1);
        $subject->forget(1);

        self::assertSame(['attachments'], $subject->get(1));
    }

    public function testAnUnconfiguredStoreSupportsNothingAndNeverAsks(): void
    {
        $this->client->expects(self::never())->method('getCapabilities');

        self::assertFalse($this->subject(false)->supports(ServerCapabilities::ATTACHMENTS, 1, true));
    }
}
