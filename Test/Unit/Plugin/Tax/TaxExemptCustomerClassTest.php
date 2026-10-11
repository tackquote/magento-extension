<?php
/**
 * Tax-exempt buyers: only TackQuote's explicit exemption, only with a class chosen, never for
 * a guest cart or an exempt caller.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Tax;

use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\Audience;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\Plugin\Tax\TaxExemptCustomerClass;

/**
 * @covers \TackQuote\Quotes\Plugin\Tax\TaxExemptCustomerClass
 */
class TaxExemptCustomerClassTest extends TestCase
{
    private const GROUP_CLASS = 3;
    private const EXEMPT_CLASS = 7;

    /** @var GroupRulesConfig&MockObject */
    private $config;

    /** @var Audience&MockObject */
    private $audience;

    protected function setUp(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->audience = $this->createMock(Audience::class);
    }

    /**
     * @return Quote&MockObject
     */
    private function quote(?int $customerId = 42): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getData')->willReturnCallback(static function ($key = '') use ($customerId) {
            return $key === 'customer_id' ? $customerId : null;
        });

        return $quote;
    }

    private function classFor(Quote $quote): int
    {
        return (int) (new TaxExemptCustomerClass($this->config, $this->audience))
            ->afterGetCustomerTaxClassId($quote, self::GROUP_CLASS);
    }

    public function testAnExemptBuyerIsTaxedAsTheExemptClass(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(self::EXEMPT_CLASS);
        $this->audience->method('forQuote')->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD', true));

        self::assertSame(self::EXEMPT_CLASS, $this->classFor($this->quote()));
    }

    public function testNotExemptKeepsTheGroupClass(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(self::EXEMPT_CLASS);
        $this->audience->method('forQuote')->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));

        self::assertSame(self::GROUP_CLASS, $this->classFor($this->quote()));
    }

    public function testAnOutageChargesTax(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(self::EXEMPT_CLASS);
        $this->audience->method('forQuote')->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE, '', true));

        self::assertSame(self::GROUP_CLASS, $this->classFor($this->quote()));
    }

    public function testNoClassChosenOrSwitchOffAsksNothing(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(0);
        $this->audience->expects(self::never())->method('forQuote');

        self::assertSame(self::GROUP_CLASS, $this->classFor($this->quote()));
    }

    public function testAGuestCartAsksNothing(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(self::EXEMPT_CLASS);
        $this->audience->expects(self::never())->method('forQuote');

        self::assertSame(self::GROUP_CLASS, $this->classFor($this->quote(null)));
    }

    public function testAnExemptCallerIsUntouched(): void
    {
        $this->config->method('getTaxExemptClassId')->willReturn(self::EXEMPT_CLASS);
        $this->audience->method('forQuote')->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::EXEMPT));

        self::assertSame(self::GROUP_CLASS, $this->classFor($this->quote()));
    }
}
