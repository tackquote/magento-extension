<?php
/**
 * The checkout panel's terms line ("Net 30 terms. $5,000.00 available."): read through the
 * same NetTermsBuyer path the method decides with, only for an active line in the quote's
 * currency, formatted by Magento's price formatter, and never breaking the checkout page.
 *
 * Mutations (applied, red, restored):
 *   T1  drop the `available !== ''` check -> testTheAvailablePartIsLeftOutWhenTheServerDidNotSendIt
 *   T2  drop the currency comparison -> testALineInAnotherCurrencyIsNotDescribed
 *   T3  drop the carriesCustomerId() check in NetTermsBuyer::standingOf() ->
 *       testNothingIsReadForACustomerTackQuoteCannotIdentify
 *   T4  drop the try/catch -> testAFailureLeavesTheLineOutAndKeepsTheRestOfTheConfig
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Payment\NetTermsBuyer;
use TackQuote\Quotes\Model\Payment\NetTermsConfigProvider;
use TackQuote\Quotes\Model\Payment\NetTermsEligibility;
use TackQuote\Quotes\Model\Payment\NetTermsStanding;
use TackQuote\Quotes\Model\ServerCapabilities;

/**
 * @covers \TackQuote\Quotes\Model\Payment\NetTermsConfigProvider
 * @covers \TackQuote\Quotes\Model\Payment\NetTermsBuyer
 */
class NetTermsConfigProviderTest extends TestCase
{
    private const INSTALL = '0f0e0d0c-0b0a-4908-8706-050403020100';

    /** @var bool */
    private $active = true;

    /** @var bool */
    private $namespaced = true;

    /** @var array|null|\Throwable */
    private $answer;

    /** @var array<string, mixed> */
    private $quoteData = [];

    /** @var int */
    private $reads = 0;

    /** @var array<int, array> Arguments of every format() call. */
    private $formatted = [];

    protected function setUp(): void
    {
        $this->answer = [
            'status' => 'standing',
            'state' => 'approved',
            'account' => [
                'status' => 'active',
                'termsDays' => 30,
                'creditLimit' => '5000.00',
                'currency' => 'USD',
                'available' => '5000.00',
            ],
        ];
        $this->quoteData = [
            'customer_id' => 7,
            'customer_is_guest' => 0,
            'customer_email' => 'buyer@example.test',
            'store_id' => 1,
            'grand_total' => '120.00',
            'quote_currency_code' => 'USD',
        ];
    }

    private function provider(): NetTermsConfigProvider
    {
        $config = $this->createMock(Config::class);
        $config->method('getInstallId')->willReturn(self::INSTALL);
        $config->method('isNetTermsPoNumberEnabled')->willReturn(true);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path) => $path === 'payment/tackquote_net_terms/active' && $this->active
        );
        $scopeConfig->method('getValue')->willReturn('Pay within your agreed terms.');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->willReturnCallback(
            fn ($key = '') => $key === '' ? $this->quoteData : ($this->quoteData[$key] ?? null)
        );
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($quote);

        $capabilities = $this->createMock(ServerCapabilities::class);
        $capabilities->method('supports')->willReturnCallback(fn () => $this->namespaced);
        $standing = $this->createMock(NetTermsStanding::class);
        $standing->method('read')->willReturnCallback(function (AccountIdentity $who, $store, bool $fresh) {
            $this->reads++;
            self::assertFalse($fresh, 'the checkout page may use the cached standing');
            self::assertTrue($who->carriesCustomerId());
            if ($this->answer instanceof \Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        });

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(function ($amount, ...$rest) {
            $this->formatted[] = array_merge([$amount], $rest);

            return '$' . number_format((float) $amount, 2);
        });

        return new NetTermsConfigProvider(
            $config,
            $scopeConfig,
            $storeManager,
            $session,
            new NetTermsBuyer(new IdentityResolver($config, $capabilities), $standing),
            new NetTermsEligibility(),
            $priceCurrency,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function terms(): string
    {
        return $this->provider()->getConfig()['payment']['tackquote_net_terms']['terms'];
    }

    public function testAnActiveLineIsDescribedWithTheAvailableCreditInTheQuoteCurrency(): void
    {
        self::assertSame('Net 30 terms. $5,000.00 available.', $this->terms());
        self::assertSame(1, $this->reads);
        self::assertSame([[5000.0, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1, 'USD']], $this->formatted);
    }

    public function testTheAvailablePartIsLeftOutWhenTheServerDidNotSendIt(): void
    {
        unset($this->answer['account']['available']);

        self::assertSame('Net 30 terms.', $this->terms());
        self::assertSame([], $this->formatted);
    }

    public function testALineInAnotherCurrencyIsNotDescribed(): void
    {
        $this->quoteData['quote_currency_code'] = 'EUR';

        self::assertSame('', $this->terms());
    }

    public function testAPendingOrInactiveStandingIsNotDescribed(): void
    {
        $this->answer = ['status' => 'standing', 'state' => 'pending', 'account' => null];
        self::assertSame('', $this->terms());

        $this->answer = ['status' => 'standing', 'state' => 'approved', 'account' => [
            'status' => 'suspended', 'termsDays' => 30, 'currency' => 'USD', 'available' => '1',
        ]];
        self::assertSame('', $this->terms());
    }

    public function testNothingIsReadForACustomerTackQuoteCannotIdentify(): void
    {
        $this->namespaced = false;

        self::assertSame('', $this->terms());
        self::assertSame(0, $this->reads);
    }

    public function testNothingIsReadForAGuestOrWhenTheMethodIsOff(): void
    {
        $this->quoteData['customer_is_guest'] = 1;
        self::assertSame('', $this->terms());

        $this->quoteData['customer_is_guest'] = 0;
        $this->active = false;
        self::assertSame('', $this->terms());

        self::assertSame(0, $this->reads);
    }

    public function testAFailureLeavesTheLineOutAndKeepsTheRestOfTheConfig(): void
    {
        $this->answer = new \RuntimeException('unreachable');

        $config = $this->provider()->getConfig()['payment']['tackquote_net_terms'];

        self::assertSame('', $config['terms']);
        self::assertTrue($config['poNumber']);
        self::assertSame('Pay within your agreed terms.', $config['description']);
    }
}
