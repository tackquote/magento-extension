<?php
/**
 * The Overview's "Magento cron has run" step reads this module's own cron jobs, and only
 * rows that actually started.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\ViewModel\Adminhtml;

use Magento\Cron\Model\ResourceModel\Schedule\Collection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\ServerCapabilities;
use TackQuote\Quotes\ViewModel\Adminhtml\CronStatus;

/**
 * @covers \TackQuote\Quotes\ViewModel\Adminhtml\CronStatus
 */
class CronStatusTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed}> */
    private $filters = [];

    /** @var ServerCapabilities|null */
    private $capabilities;

    /**
     * @param string|null $executedAt
     * @return CronStatus
     */
    private function cronStatus(?string $executedAt): CronStatus
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'setOrder', 'setPageSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use ($collection) {
            $this->filters[] = [$field, $condition];

            return $collection;
        });
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            new DataObject($executedAt === null ? [] : ['executed_at' => $executedAt])
        );

        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->expects(self::once())->method('create')->willReturn($collection);

        return new CronStatus(
            $factory,
            $this->capabilities ?? $this->createMock(ServerCapabilities::class),
            $this->createMock(StoreManagerInterface::class)
        );
    }

    public function testNoStartedRowMeansCronHasNotRun(): void
    {
        $status = $this->cronStatus(null);

        self::assertFalse($status->hasRun());
        self::assertNull($status->getLastRunAt());
    }

    public function testAStartedRowMeansCronHasRun(): void
    {
        $status = $this->cronStatus('2026-10-11 14:05:00');

        self::assertTrue($status->hasRun());
        self::assertSame('2026-10-11 14:05:00', $status->getLastRunAt());
    }

    public function testOnlyThisModulesJobsThatStartedAreRead(): void
    {
        $status = $this->cronStatus(null);
        $status->hasRun();
        $status->hasRun(); // read once, then remembered (the factory expects one create()).

        self::assertSame(
            [
                ['job_code', ['in' => ['tackquote_warm_registration_config', 'tackquote_warm_server_capabilities']]],
                ['executed_at', ['notnull' => true]],
            ],
            $this->filters
        );
    }

    public function testTheLastFailedServerCheckIsPassedThrough(): void
    {
        $failure = ['at' => '2026-10-11 14:05:00', 'storeId' => 1, 'reason' => 'HTTP 503: Service Unavailable'];
        $this->capabilities = $this->createMock(ServerCapabilities::class);
        $this->capabilities->expects(self::once())->method('lastFailure')->willReturn($failure);
        $this->capabilities->expects(self::never())->method('get');
        $this->capabilities->expects(self::never())->method('refresh');

        $status = $this->cronStatus(null);
        $status->hasRun();

        self::assertSame($failure, $status->getLastPingFailure());
    }

    /**
     * Devstore Q/A finding #2. Mutations (applied, red, restored):
     *   N1  answer '' for a waiting state -> this test
     *   N2  read the state for store 0 instead of the default store view -> this test
     *
     * @dataProvider serverStates
     */
    public function testTheServerCheckNoteForTheDefaultStoreView(string $state, ?array $failure, string $note): void
    {
        $capabilities = $this->createMock(ServerCapabilities::class);
        $capabilities->expects(self::once())->method('state')->with(3)->willReturn($state);
        $capabilities->method('lastFailure')->willReturn($failure);
        $capabilities->expects(self::never())->method('get');
        $capabilities->expects(self::never())->method('refresh');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        self::assertSame($note, (new CronStatus($factory, $capabilities, $storeManager))->getServerCheckNote());
    }

    /**
     * @return array<string, array{0: string, 1: array|null, 2: string}>
     */
    public static function serverStates(): array
    {
        $failure = ['at' => '2026-10-11 14:05:00', 'storeId' => 3, 'reason' => 'HTTP 503: Service Unavailable'];

        return [
            'known' => [ServerCapabilities::STATE_OK, null, ''],
            'waiting' => [ServerCapabilities::STATE_WAITING, null, 'Waiting for the first server check.'],
            'failed' => [
                ServerCapabilities::STATE_FAILED,
                $failure,
                'Last server check failed: HTTP 503: Service Unavailable',
            ],
            'failed, record expired' => [ServerCapabilities::STATE_FAILED, null, 'The last server check failed.'],
        ];
    }

    public function testTheJobCodesAreTheOnesDeclaredInCrontab(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 4) . '/etc/crontab.xml');
        preg_match_all('/<job name="([^"]+)"/', $xml, $matches);
        $declared = $matches[1];
        sort($declared);
        $codes = CronStatus::JOB_CODES;
        sort($codes);

        self::assertSame($declared, $codes);
    }
}
