<?php
/**
 * What the block reads, and how often, while a shopper waits for HTML.
 *
 * Block\QuoteList renders in `before.body.end` on every storefront page that is not served
 * from the full-page cache. It used to read the registration policy and the capability list
 * from CACHE ONLY, so after every `cache:flush`, settings save or before the first cron run the
 * form lost its company step, its custom fields and its attachment control for up to five
 * minutes (devstore Q/A findings #2, #12, #16). It now reads through the providers' get()
 * paths, which answer from cache and ask TackQuote once when the cache is cold. What bounds the
 * cost of that is pinned in the provider tests: the 3-second read timeout of the API client and
 * the 60-second memory of a failure (RegistrationConfigProviderTest, ServerCapabilitiesTest).
 * These tests pin the caller: one read per render, per store view.
 *
 * Mutations (applied, red, restored):
 *   Q1  back to getCached() -> testACacheMissIsFetchedOnceInsteadOfDegradingUntilCron
 *   Q2  supports(..., false) -> testTheAttachmentControlAsksTheServerWhenTheCacheIsCold
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Block\QuoteList;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteRequestConfig;
use TackQuote\Quotes\Model\RegistrationConfigProvider;
use TackQuote\Quotes\Model\ServerCapabilities;

/**
 * @covers \TackQuote\Quotes\Block\QuoteList
 */
class QuoteListRenderPathTest extends TestCase
{
    /** @var RegistrationConfigProvider&MockObject */
    private $provider;

    /** @var Config&MockObject */
    private $config;

    /** @var QuoteRequestConfig&MockObject */
    private $quoteRequestConfig;

    /** @var ServerCapabilities&MockObject */
    private $capabilities;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(RegistrationConfigProvider::class);
        $this->config = $this->createMock(Config::class);
        $this->quoteRequestConfig = $this->createMock(QuoteRequestConfig::class);
        $this->capabilities = $this->createMock(ServerCapabilities::class);
    }

    private function block(int $storeId = 7): QuoteList
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);

        return new QuoteList(
            $context,
            $this->config,
            $this->provider,
            $this->quoteRequestConfig,
            $this->capabilities
        );
    }

    public function testACacheMissIsFetchedOnceInsteadOfDegradingUntilCron(): void
    {
        // get() answers from cache and fetches on a miss; refresh() always asks and is cron's.
        $this->provider->expects(self::never())->method('refresh');
        $this->provider->expects(self::never())->method('getCached');
        $this->provider->expects(self::once())->method('get')->willReturn(['mode' => 'both']);

        self::assertSame(['mode' => 'both'], $this->block()->getRegistrationConfig());
    }

    public function testTheStoreIdIsPassedThroughSoEachStoreViewGetsItsOwnPolicy(): void
    {
        $this->provider->expects(self::once())
            ->method('get')
            ->with(42)
            ->willReturn(null);

        self::assertNull($this->block(42)->getRegistrationConfig());
    }

    public function testThePolicyIsReadOnceEvenThoughTheTemplateAsksFiveTimes(): void
    {
        // quote-list.phtml asks isCompanyStepEnabled / isCompanyRequired /
        // getRequiredCompanyFields / getCustomFields / getRegistrationConfigJson, and each
        // one used to be its own cache round trip.
        $this->provider->expects(self::once())->method('get')->willReturn(['allowCompany' => true]);

        $block = $this->block();
        for ($i = 0; $i < 5; $i++) {
            $block->getRegistrationConfig();
        }
    }

    public function testAnUnavailablePolicyIsMemoisedTooRatherThanRetriedEveryCall(): void
    {
        // Null is a real policy value ("we know it is unavailable"), so memoisation cannot
        // key off the value alone — without the separate loaded flag every one of the five
        // template questions would go back to the cache (or, cold, to TackQuote).
        $this->provider->expects(self::once())->method('get')->willReturn(null);

        $block = $this->block();
        self::assertNull($block->getRegistrationConfig());
        self::assertNull($block->getRegistrationConfig());
        self::assertNull($block->getRegistrationConfig());
    }

    /**
     * The same for the server's capability list: the attachment control may ask the server
     * when nothing is cached (supports(..., true)), never force a refresh.
     */
    public function testTheAttachmentControlAsksTheServerWhenTheCacheIsCold(): void
    {
        $this->quoteRequestConfig->method('isAttachmentsSwitchOn')->willReturn(true);
        $this->capabilities->expects(self::never())->method('refresh');
        $this->capabilities->expects(self::once())->method('supports')
            ->with(ServerCapabilities::ATTACHMENTS, 7, true)
            ->willReturn(true);

        self::assertTrue($this->block()->isAttachmentsEnabled());
    }

    public function testNoAttachmentControlWhenTheMerchantSwitchIsOff(): void
    {
        $this->quoteRequestConfig->method('isAttachmentsSwitchOn')->willReturn(false);
        $this->capabilities->method('supports')->willReturn(true);

        self::assertFalse($this->block()->isAttachmentsEnabled());
        self::assertNull($this->block()->getRequestExtrasConfig()['attachments']);
    }
}
