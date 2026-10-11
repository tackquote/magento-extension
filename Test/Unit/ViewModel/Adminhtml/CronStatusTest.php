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
use TackQuote\Quotes\ViewModel\Adminhtml\CronStatus;

/**
 * @covers \TackQuote\Quotes\ViewModel\Adminhtml\CronStatus
 */
class CronStatusTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed}> */
    private $filters = [];

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

        return new CronStatus($factory);
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
