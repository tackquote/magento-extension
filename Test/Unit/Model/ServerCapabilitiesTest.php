<?php
/**
 * The capability list: fetched once when the cache is cold, cached for an hour, a failure
 * remembered for 60 seconds and logged and recorded, and not dropped by an unrelated settings
 * save (devstore Q/A findings #12, #15, #16).
 *
 * Mutations (applied, red, restored):
 *   S1  get() answers [] on a miss instead of fetching -> testAColdCacheIsFetchedOnceAndPopulated
 *   S2  a failed ping overwrites a cached list with [] -> testAFailedRefreshKeepsTheKnownList
 *   S3  a failed ping is not remembered -> testAFailedPingIsRememberedForSixtySecondsOnly
 *   S4  the entry tagged TACKQUOTE_CONFIG again -> testAnUnrelatedSettingsSaveKeepsTheEntry
 *   S5  the credential hash not compared on load -> testANewApiKeyOrUrlIsAMissAndAsksAgain
 *   S6  no warning on failure -> testAFailureIsLoggedWithTheStatusAndRecordedForTheOverview
 *   S7  the failure record not cleared on success -> testASuccessForTheSameStoreClearsTheRecord
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;
use TackQuote\Quotes\Model\ServerCapabilities;
use TackQuote\Quotes\Observer\FlushRegistrationConfig;

/**
 * @covers \TackQuote\Quotes\Model\ServerCapabilities
 */
class ServerCapabilitiesTest extends TestCase
{
    private const KEY_1 = 'tackquote_server_capabilities_1';

    /** @var array<string, array{data: string, tags: string[], ttl: int|null}> The fake cache. */
    private $store = [];

    /** @var Client&MockObject */
    private $client;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var string */
    private $apiKey = 'key-one';

    /** @var string */
    private $apiUrl = 'https://api.example.test/v1';

    protected function setUp(): void
    {
        $this->store = [];
        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCapabilities', 'getRegistrationConfig'])
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(fn ($key) => $this->store[$key]['data'] ?? false);
        $this->cache->method('save')->willReturnCallback(function ($data, $key, $tags = [], $ttl = null) {
            $this->store[$key] = ['data' => (string) $data, 'tags' => (array) $tags, 'ttl' => $ttl];

            return true;
        });
        $this->cache->method('remove')->willReturnCallback(function ($key) {
            unset($this->store[$key]);

            return true;
        });
        $this->cache->method('clean')->willReturnCallback(function ($tags = []) {
            foreach ($this->store as $key => $entry) {
                if (array_intersect((array) $tags, $entry['tags']) !== []) {
                    unset($this->store[$key]);
                }
            }

            return true;
        });
    }

    /**
     * @param bool $configured
     * @return Config&MockObject
     */
    private function config(bool $configured = true)
    {
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured', 'getApiKey', 'getApiBaseUrl'])
            ->getMock();
        $config->method('isConfigured')->willReturn($configured);
        $config->method('getApiKey')->willReturnCallback(fn () => $this->apiKey);
        $config->method('getApiBaseUrl')->willReturnCallback(fn () => $this->apiUrl);

        return $config;
    }

    private function subject(bool $configured = true): ServerCapabilities
    {
        return new ServerCapabilities(
            $this->client,
            $this->cache,
            new Json(),
            $this->config($configured),
            $this->logger
        );
    }

    public function testAColdCacheIsFetchedOnceAndPopulated(): void
    {
        $this->client->expects(self::once())->method('getCapabilities')->with(1)
            ->willReturn(['ok' => true, 'capabilities' => ['targetPrice', 'attachments', 'customerIdentity', 7]]);
        $subject = $this->subject();

        self::assertSame([], $this->store, 'the cache starts cold');
        self::assertTrue($subject->supports('customerIdentity', 1, true));
        self::assertTrue($subject->supports(ServerCapabilities::ATTACHMENTS, 1, true));
        self::assertSame(['targetPrice', 'attachments', 'customerIdentity'], $subject->getCached(1));
        self::assertSame(3600, $this->store[self::KEY_1]['ttl']);
        self::assertSame(ServerCapabilities::STATE_OK, $subject->state(1));
    }

    public function testGetCachedAndACacheOnlyCheckNeverAskTheServer(): void
    {
        $this->client->expects(self::never())->method('getCapabilities');

        self::assertSame([], $this->subject()->getCached(1));
        self::assertFalse($this->subject()->supports(ServerCapabilities::ATTACHMENTS, 1, false));
        self::assertSame(ServerCapabilities::STATE_WAITING, $this->subject()->state(1));
    }

    public function testAnOlderServerListsNothing(): void
    {
        $this->client->method('getCapabilities')->willReturn(['ok' => true, 'capabilities' => []]);

        self::assertFalse($this->subject()->supports(ServerCapabilities::ATTACHMENTS, 1, true));
    }

    public function testAFailedPingIsRememberedForSixtySecondsOnly(): void
    {
        $this->client->expects(self::once())->method('getCapabilities')->willReturn(['ok' => false]);
        $subject = $this->subject();

        self::assertSame([], $subject->get(1));
        self::assertSame([], $subject->get(1));
        self::assertSame(60, $this->store[self::KEY_1]['ttl']);
        self::assertSame(ServerCapabilities::STATE_FAILED, $subject->state(1));
    }

    public function testAFailedRefreshKeepsTheKnownList(): void
    {
        $this->client->method('getCapabilities')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'capabilities' => ['attachments']],
            ['ok' => false, 'status' => 503, 'message' => 'Service Unavailable']
        );
        $subject = $this->subject();
        $subject->refresh(1);

        self::assertSame(['attachments'], $subject->refresh(1));
        self::assertSame(['attachments'], $subject->getCached(1));
        self::assertSame(ServerCapabilities::STATE_OK, $subject->state(1));
    }

    public function testAFailureIsLoggedWithTheStatusAndRecordedForTheOverview(): void
    {
        $this->client->method('getCapabilities')
            ->willReturn(['ok' => false, 'status' => 401, 'message' => 'Invalid API key']);
        $this->logger->expects(self::once())->method('warning')->with(self::logicalAnd(
            self::stringContains('store 2'),
            self::stringContains('HTTP 401: Invalid API key')
        ));
        $subject = $this->subject();

        $subject->refresh(2);

        $failure = $subject->lastFailure();
        self::assertNotNull($failure);
        self::assertSame(2, $failure['storeId']);
        self::assertSame('HTTP 401: Invalid API key', $failure['reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $failure['at']);
        self::assertStringNotContainsString($this->apiKey, (string) json_encode($this->store));
    }

    public function testASuccessForTheSameStoreClearsTheRecord(): void
    {
        $this->client->method('getCapabilities')->willReturnOnConsecutiveCalls(
            ['ok' => false, 'message' => 'Could not reach the TackQuote API.'],
            ['ok' => true, 'capabilities' => ['attachments']],
            ['ok' => false, 'message' => 'Could not reach the TackQuote API.'],
            ['ok' => true, 'capabilities' => []]
        );
        $subject = $this->subject();

        $subject->refresh(1);
        $subject->refresh(1);
        self::assertNull($subject->lastFailure());

        $subject->refresh(1);
        $subject->refresh(3);
        self::assertSame(1, $subject->lastFailure()['storeId'] ?? null, 'another store\'s success keeps it');
    }

    public function testAnUnrelatedSettingsSaveKeepsTheEntry(): void
    {
        $this->client->expects(self::once())->method('getCapabilities')
            ->willReturn(['ok' => true, 'capabilities' => ['customerIdentity']]);
        $subject = $this->subject();
        $subject->get(1);

        // What saving the TackQuote section runs (etc/adminhtml/events.xml): the observer cleans
        // the TACKQUOTE_CONFIG tag through the registration-config provider.
        $this->store['tackquote_registration_config_1'] = [
            'data' => '{}',
            'tags' => ['TACKQUOTE_CONFIG'],
            'ttl' => 3600,
        ];
        $registration = new RegistrationConfigProvider(
            $this->client,
            $this->cache,
            new Json(),
            $this->config(),
            $this->logger
        );
        (new FlushRegistrationConfig($registration))->execute(new Observer());

        self::assertArrayNotHasKey('tackquote_registration_config_1', $this->store, 'control: the save flushed');
        self::assertTrue($subject->supports('customerIdentity', 1, true), 'answered from cache, no second ping');
    }

    public function testANewApiKeyOrUrlIsAMissAndAsksAgain(): void
    {
        $this->client->expects(self::exactly(3))->method('getCapabilities')
            ->willReturn(['ok' => true, 'capabilities' => ['attachments']]);
        $subject = $this->subject();
        $subject->get(1);
        $subject->get(1);

        $this->apiKey = 'key-two';
        self::assertSame([], $subject->getCached(1), 'an entry read with the old key is not served');
        $subject->get(1);

        $this->apiUrl = 'https://other.example.test/v1';
        $subject->get(1);
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

    public function testAnEntryFromAnOlderModuleVersionIsAMiss(): void
    {
        $this->store[self::KEY_1] = ['data' => '["attachments"]', 'tags' => [], 'ttl' => 3600];
        $this->client->expects(self::once())->method('getCapabilities')
            ->willReturn(['ok' => true, 'capabilities' => []]);

        self::assertSame([], $this->subject()->get(1));
    }

    public function testAnUnconfiguredStoreSupportsNothingAndNeverAsks(): void
    {
        $this->client->expects(self::never())->method('getCapabilities');

        self::assertFalse($this->subject(false)->supports(ServerCapabilities::ATTACHMENTS, 1, true));
    }
}
