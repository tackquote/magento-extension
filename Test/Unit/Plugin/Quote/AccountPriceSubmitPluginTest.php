<?php
/**
 * At order submit TackQuote is asked again and a cached price never reaches the order.
 *
 * The last test wires the real applier and resolver together over a cache that still holds an
 * older TackQuote price, so it proves the property end to end: the order line carries the price
 * TackQuote answers at submit, not the cached one.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Enforce\AnswerMapper;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;
use TackQuote\Quotes\Model\Enforce\Eligibility;
use TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch;
use TackQuote\Quotes\Model\Enforce\PriceResolver;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Quote\AccountPriceSubmitPlugin;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\AccountPriceSubmitPlugin
 */
class AccountPriceSubmitPluginTest extends TestCase
{
    /** @var int */
    private $collections = 0;

    private function quote(array $items = []): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'getStoreId', 'collectTotals'])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturn($items);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('collectTotals')->willReturnCallback(function () use ($quote) {
            $this->collections++;
            self::assertFalse($quote->getData('totals_collected_flag'), 'the flag is cleared first');

            return $quote;
        });
        $quote->setData('totals_collected_flag', true);

        return $quote;
    }

    public function testAFreshAnswerIsAskedForAndTotalsAreRecollectedWhenAPriceChanged(): void
    {
        $quote = $this->quote();
        $applier = $this->createMock(CustomPriceApplier::class);
        $applier->expects(self::once())->method('apply')->with($quote, true)->willReturn(true);

        (new AccountPriceSubmitPlugin($applier))->beforeSubmit($this->createMock(QuoteManagement::class), $quote);

        self::assertSame(1, $this->collections);
    }

    public function testUnchangedPricesLeaveTheCollectedTotalsAlone(): void
    {
        $applier = $this->createMock(CustomPriceApplier::class);
        $applier->method('apply')->willReturn(false);

        (new AccountPriceSubmitPlugin($applier))->beforeSubmit(
            $this->createMock(QuoteManagement::class),
            $this->quote()
        );

        self::assertSame(0, $this->collections);
    }

    public function testAStaleCachedPriceIsNeverChargedAtSubmit(): void
    {
        $line = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem'])
            ->getMock();
        $line->setData(['product_type' => 'simple', 'sku' => 'A', 'qty' => 2, 'product_id' => 5]);
        $quote = $this->quote([$line]);

        $cacheData = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function ($id) use (&$cacheData) {
            return $cacheData[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(static function ($data, $id) use (&$cacheData) {
            $cacheData[$id] = (string) $data;

            return true;
        });

        $price = 7.5;
        $client = $this->createMock(StorefrontClient::class);
        $client->method('resolvePricing')->willReturnCallback(static function () use (&$price) {
            return ['ok' => true, 'status' => 201, 'data' => ['buyerMatched' => true, 'items' => [
                ['sku' => 'A', 'quantity' => 2, 'unitPrice' => $price, 'source' => 'price_book'],
            ]]];
        });
        $client->method('get')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['status' => 'priced', 'currency' => 'USD']
        ]);

        $eligibility = $this->createMock(Eligibility::class);
        $eligibility->method('buyer')->willReturn(
            ['storeId' => 1, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD']
        );
        $switch = $this->createMock(PriceEnforcementSwitch::class);
        $switch->method('isOn')->willReturn(true);

        $quoteOnly = $this->createMock(QuoteOnlyMode::class);
        $build = function () use ($eligibility, $switch, $quoteOnly, $client, $cache): CustomPriceApplier {
            return new CustomPriceApplier(
                $eligibility,
                $switch,
                $quoteOnly,
                new PriceResolver($client, new AnswerMapper(), $cache, new Json()),
                new Json()
            );
        };

        // The cart is priced (and cached) at 7.5.
        $build()->apply($quote);
        self::assertSame(7.5, $line->getData('custom_price'));

        // TackQuote's price changes; the cache still says 7.5. A new request places the order.
        $price = 9.25;
        $build()->apply($quote);
        self::assertSame(7.5, $line->getData('custom_price'), 'control: the cart path serves the cached price');

        (new AccountPriceSubmitPlugin($build()))->beforeSubmit($this->createMock(QuoteManagement::class), $quote);

        self::assertSame(9.25, $line->getData('custom_price'));
        self::assertSame(9.25, $line->getCalculationPrice(), 'the order line\'s price (calculation_price)');
        self::assertSame(1, $this->collections, 'totals recollected before the order is built');
    }
}
