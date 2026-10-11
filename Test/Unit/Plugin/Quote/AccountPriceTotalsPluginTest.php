<?php
/**
 * Account prices go on the lines before totals are collected, never under totals that will not
 * be recomputed, and a failure never breaks the cart.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Quote;

use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;
use TackQuote\Quotes\Plugin\Quote\AccountPriceTotalsPlugin;

/**
 * @covers \TackQuote\Quotes\Plugin\Quote\AccountPriceTotalsPlugin
 */
class AccountPriceTotalsPluginTest extends TestCase
{
    private function quote(bool $collected): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getStoreId'])->getMock();
        $quote->setData('totals_collected_flag', $collected);

        return $quote;
    }

    public function testPricesAreAppliedBeforeAnUncollectedTotal(): void
    {
        $quote = $this->quote(false);
        $applier = $this->createMock(CustomPriceApplier::class);
        $applier->expects(self::once())->method('apply')->with($quote, false);

        (new AccountPriceTotalsPlugin($applier, $this->createMock(LoggerInterface::class)))->beforeCollectTotals($quote);
    }

    public function testNothingIsChangedUnderTotalsThatWillNotBeRecomputed(): void
    {
        $applier = $this->createMock(CustomPriceApplier::class);
        $applier->expects(self::never())->method('apply');

        (new AccountPriceTotalsPlugin($applier, $this->createMock(LoggerInterface::class)))
            ->beforeCollectTotals($this->quote(true));
    }

    public function testAFailureIsLoggedAndTheCartStillCollects(): void
    {
        $applier = $this->createMock(CustomPriceApplier::class);
        $applier->method('apply')->willThrowException(new \RuntimeException('buyer@example.test'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::callback(static function ($message): bool {
            return strpos((string) $message, 'buyer@example.test') === false;
        }));

        (new AccountPriceTotalsPlugin($applier, $logger))->beforeCollectTotals($this->quote(false));
    }
}
