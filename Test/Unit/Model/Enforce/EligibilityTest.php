<?php
/**
 * Whose cart checkout enforcement may act on: the signed-in owner, inside the B2B groups,
 * never an exempt (admin / integration) caller.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use Magento\Directory\Model\Currency;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Enforce\Eligibility;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\Eligibility
 */
class EligibilityTest extends TestCase
{
    private function visitor(bool $exempt, bool $loggedIn, ?int $id, int $group, string $email): VisitorContextInterface
    {
        $visitor = $this->createMock(VisitorContextInterface::class);
        $visitor->method('isExempt')->willReturn($exempt);
        $visitor->method('isLoggedIn')->willReturn($loggedIn);
        $visitor->method('getCustomerId')->willReturn($id);
        $visitor->method('getCustomerGroupId')->willReturn($group);
        $visitor->method('getCustomerEmail')->willReturn($email);

        return $visitor;
    }

    /**
     * A quote; $currency null is a quote never saved, which has no currency code yet.
     */
    private function quote(int $customerId, ?string $currency = 'usd', string $storeCurrency = 'EUR'): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($storeCurrency);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getStore'])
            ->getMock();
        $quote->method('getStoreId')->willReturn(3);
        $quote->method('getStore')->willReturn($store);
        $quote->setData('customer_id', $customerId);
        if ($currency !== null) {
            $quote->setData('quote_currency_code', $currency);
        }

        return $quote;
    }

    private function eligibility(VisitorContextInterface $visitor, string $groups = ''): Eligibility
    {
        $config = $this->createMock(Config::class);
        $config->method('getB2bCustomerGroups')->with(3)->willReturn($groups);

        return new Eligibility($config, $visitor);
    }

    public function testTheSignedInOwnerIsTheBuyer(): void
    {
        $buyer = $this->eligibility($this->visitor(false, true, 7, 2, ' Buyer@Example.Test '))->buyer($this->quote(7));

        self::assertSame(
            ['storeId' => 3, 'customerId' => 7, 'email' => 'buyer@example.test', 'currency' => 'USD'],
            $buyer
        );
    }

    public function testAnExemptCallerIsNeverEnforced(): void
    {
        $visitor = $this->visitor(true, true, 7, 2, 'b@example.test');
        self::assertNull($this->eligibility($visitor)->buyer($this->quote(7)));
    }

    public function testAGuestIsNeverEnforced(): void
    {
        self::assertNull($this->eligibility($this->visitor(false, false, null, 0, ''))->buyer($this->quote(0)));
    }

    public function testSomebodyElsesCartIsNeverPricedAsTheVisitor(): void
    {
        $visitor = $this->visitor(false, true, 7, 2, 'b@example.test');
        self::assertNull($this->eligibility($visitor)->buyer($this->quote(8)));
    }

    public function testTheB2bGroupFilterApplies(): void
    {
        $visitor = $this->visitor(false, true, 7, 2, 'b@example.test');

        self::assertNull($this->eligibility($visitor, '3,4')->buyer($this->quote(7)));
        self::assertNotNull($this->eligibility($visitor, '2,4')->buyer($this->quote(7)));
    }

    public function testNoEmailOrNoCurrencyAnywhereMeansNoBuyer(): void
    {
        self::assertNull($this->eligibility($this->visitor(false, true, 7, 2, ''))->buyer($this->quote(7)));
        $visitor = $this->visitor(false, true, 7, 2, 'b@example.test');
        self::assertNull($this->eligibility($visitor)->buyer($this->quote(7, '', '')));
    }

    /**
     * Q/A finding #39: the first add to a brand-new cart runs on a quote Magento has not saved
     * yet, so it has no quote_currency_code. That cart is still this buyer's, in the currency
     * Quote::beforeSave() is about to give it (the store's current currency).
     */
    public function testANewUnsavedCartIsTheBuyersInTheStoresCurrentCurrency(): void
    {
        $visitor = $this->visitor(false, true, 7, 2, 'b@example.test');

        self::assertSame(
            ['storeId' => 3, 'customerId' => 7, 'email' => 'b@example.test', 'currency' => 'EUR'],
            $this->eligibility($visitor)->buyer($this->quote(7, null, 'eur'))
        );
        self::assertSame('USD', $this->eligibility($visitor)->buyer($this->quote(7, 'usd', 'EUR'))['currency']);
    }

    public function testANewCartWithAForcedCurrencyUsesIt(): void
    {
        $forced = $this->createMock(Currency::class);
        $forced->method('getCode')->willReturn('gbp');
        $quote = $this->quote(7, null, 'EUR');
        $quote->setData('forced_currency', $forced);

        $buyer = $this->eligibility($this->visitor(false, true, 7, 2, 'b@example.test'))->buyer($quote);

        self::assertSame('GBP', $buyer['currency']);
    }
}
