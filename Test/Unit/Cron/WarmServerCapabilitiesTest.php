<?php
/**
 * The cron job is the only thing that puts the server's capability list in cache for the
 * page render (Block\QuoteList reads it from cache only).
 *
 * Mutations (applied, red, restored):
 *   W1  drop the attachment-switch check -> testOnlyActiveStoresWithAttachmentsOnAreWarmed
 *   W2  drop the try/catch -> testOneFailingStoreDoesNotStopTheOthersAndIsLogged
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Cron\WarmServerCapabilities;
use TackQuote\Quotes\Model\QuoteRequestConfig;
use TackQuote\Quotes\Model\ServerCapabilities;

/**
 * @covers \TackQuote\Quotes\Cron\WarmServerCapabilities
 */
class WarmServerCapabilitiesTest extends TestCase
{
    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var QuoteRequestConfig&MockObject */
    private $config;

    /** @var ServerCapabilities&MockObject */
    private $capabilities;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = $this->createMock(QuoteRequestConfig::class);
        $this->capabilities = $this->createMock(ServerCapabilities::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<int, bool> $activeById
     */
    private function stores(array $activeById): void
    {
        $stores = [];
        foreach ($activeById as $id => $isActive) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getIsActive')->willReturn($isActive);
            $stores[] = $store;
        }
        $this->storeManager->method('getStores')->willReturn($stores);
    }

    private function job(): WarmServerCapabilities
    {
        return new WarmServerCapabilities($this->storeManager, $this->config, $this->capabilities, $this->logger);
    }

    public function testOnlyActiveStoresWithAttachmentsOnAreWarmed(): void
    {
        $this->stores([1 => true, 2 => false, 3 => true]);
        $this->config->method('isAttachmentsSwitchOn')->willReturnCallback(static function ($storeId) {
            return $storeId !== 3;
        });
        $warmed = [];
        $this->capabilities->method('refresh')->willReturnCallback(static function ($storeId) use (&$warmed) {
            $warmed[] = $storeId;

            return ['attachments'];
        });

        $this->job()->execute();

        self::assertSame([1], $warmed);
    }

    public function testOneFailingStoreDoesNotStopTheOthersAndIsLogged(): void
    {
        $this->stores([1 => true, 2 => true]);
        $this->config->method('isAttachmentsSwitchOn')->willReturn(true);
        $warmed = [];
        $this->capabilities->method('refresh')->willReturnCallback(static function ($storeId) use (&$warmed) {
            if ($storeId === 1) {
                throw new \RuntimeException('store config unreadable');
            }
            $warmed[] = $storeId;

            return [];
        });
        $this->logger->expects(self::once())->method('warning')
            ->with(self::stringContains('store 1'));

        $this->job()->execute();

        self::assertSame([2], $warmed);
    }
}
