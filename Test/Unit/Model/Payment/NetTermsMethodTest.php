<?php
/**
 * "Pay on net terms": offered only to a signed-in customer TackQuote allows terms for, with
 * the method switched on, and hidden on any failure; re-checked fresh at order placement;
 * the terms and the PO number recorded on the payment.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Payment\NetTermsEligibility;
use TackQuote\Quotes\Model\Payment\NetTermsMethod;
use TackQuote\Quotes\Model\Payment\NetTermsStanding;

/**
 * @covers \TackQuote\Quotes\Model\Payment\NetTermsMethod
 */
class NetTermsMethodTest extends TestCase
{
    private const INSTALL = '0f0e0d0c-0b0a-4908-8706-050403020100';

    /** @var bool */
    private $active = true;

    /** @var bool */
    private $namespaced = true;

    /** @var bool */
    private $poEnabled = true;

    /** @var array|null|\Throwable */
    private $answer;

    /** @var bool[] The `$fresh` flag of every standing read. */
    private $reads = [];

    protected function setUp(): void
    {
        $this->answer = [
            'status' => 'standing',
            'state' => 'approved',
            'account' => [
                'status' => 'active',
                'termsDays' => 45,
                'creditLimit' => '1000',
                'currency' => 'USD',
                'available' => '500'
            ],
        ];
    }

    private function method(): NetTermsMethod
    {
        $handler = $this->createMock(ValueHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            fn (array $subject) => ['active' => $this->active ? '1' : '0'][$subject['field']] ?? null
        );
        $pool = $this->createMock(ValueHandlerPoolInterface::class);
        $pool->method('get')->willReturn($handler);

        $config = $this->createMock(Config::class);
        $config->method('getInstallId')->willReturn(self::INSTALL);
        $config->method('isNetTermsPoNumberEnabled')->willReturnCallback(fn () => $this->poEnabled);
        $capabilities = $this->createMock(\TackQuote\Quotes\Model\ServerCapabilities::class);
        $capabilities->method('supports')->willReturnCallback(fn () => $this->namespaced);

        $standing = $this->createMock(NetTermsStanding::class);
        $standing->method('read')->willReturnCallback(function (AccountIdentity $who, $store, bool $fresh) {
            $this->reads[] = $fresh;
            if ($this->answer instanceof \Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        });

        return new NetTermsMethod(
            $this->createMock(ManagerInterface::class),
            $pool,
            $this->createMock(PaymentDataObjectFactory::class),
            new IdentityResolver($config, $capabilities),
            $standing,
            new NetTermsEligibility(),
            $config,
            $this->createMock(LoggerInterface::class),
            NetTermsMethod::CODE,
            \Magento\Payment\Block\Form::class,
            \TackQuote\Quotes\Block\Payment\NetTermsInfo::class,
            null,
            null,
            null,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array $data
     * @return Quote&MockObject
     */
    private function quote(array $data = [])
    {
        $data = array_merge([
            'customer_id' => 7,
            'customer_is_guest' => 0,
            'customer_email' => 'buyer@example.test',
            'store_id' => 1,
            'grand_total' => '120.00',
            'quote_currency_code' => 'USD',
        ], $data);
        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key = '') => $key === '' ? $data : ($data[$key] ?? null));
        $quote->method('getStoreId')->willReturn($data['store_id']);

        return $quote;
    }

    public function testOfferedToASignedInCustomerTackQuoteAllowsTermsFor(): void
    {
        self::assertTrue($this->method()->isAvailable($this->quote()));
        self::assertSame([false], $this->reads, 'the checkout page may use the cached standing');
    }

    public function testNotOfferedWhenSwitchedOff(): void
    {
        $this->active = false;
        self::assertFalse($this->method()->isAvailable($this->quote()));
        self::assertSame([], $this->reads);
    }

    public function testNotOfferedToAGuestOrWithoutAQuote(): void
    {
        self::assertFalse($this->method()->isAvailable($this->quote([
            'customer_id' => null,
            'customer_is_guest' => 1
        ])));
        self::assertFalse($this->method()->isAvailable($this->quote(['customer_is_guest' => 1])));
        self::assertFalse($this->method()->isAvailable(null));
        self::assertSame([], $this->reads);
    }

    public function testNotOfferedWhenTheServerCannotIdentifyTheMagentoCustomer(): void
    {
        $this->namespaced = false;
        self::assertFalse($this->method()->isAvailable($this->quote()));
        self::assertSame([], $this->reads, 'an email alone never reads credit');
    }

    public function testNotOfferedWhenStandingDoesNotAllowIt(): void
    {
        self::assertFalse($this->method()->isAvailable($this->quote(['grand_total' => '500.01'])));
        self::assertFalse($this->method()->isAvailable($this->quote(['quote_currency_code' => 'EUR'])));
    }

    public function testHiddenOnAnyFailureNeverOffered(): void
    {
        $this->answer = null;
        self::assertFalse($this->method()->isAvailable($this->quote()));
        $this->answer = new \RuntimeException('cache down');
        self::assertFalse($this->method()->isAvailable($this->quote()));
    }

    private function orderPayment(array $orderData, array $info = []): InfoInterface
    {
        $order = new DataObject(array_merge([
            'customer_id' => 7,
            'customer_is_guest' => 0,
            'customer_email' => 'buyer@example.test',
            'store_id' => 1,
            'grand_total' => '120.00',
            'order_currency_code' => 'USD',
        ], $orderData));

        return new class ($order, $info) extends DataObject implements InfoInterface {
            /** @var DataObject */
            private $order;

            public function __construct(DataObject $order, array $info)
            {
                parent::__construct(['additional_information' => $info]);
                $this->order = $order;
            }

            public function getOrder()
            {
                return $this->order;
            }

            public function encrypt($data)
            {
                return $data;
            }

            public function decrypt($data)
            {
                return $data;
            }

            public function setAdditionalInformation($key, $value = null)
            {
                $all = (array) $this->getData('additional_information');
                $all[$key] = $value;
                $this->setData('additional_information', $all);

                return $this;
            }

            public function hasAdditionalInformation($key = null)
            {
                return array_key_exists($key, (array) $this->getData('additional_information'));
            }

            public function unsAdditionalInformation($key = null)
            {
                return $this;
            }

            public function getAdditionalInformation($key = null)
            {
                $all = (array) $this->getData('additional_information');

                return $key === null ? $all : ($all[$key] ?? null);
            }

            public function getMethodInstance()
            {
                return null;
            }
        };
    }

    public function testPlacingTheOrderRechecksFreshAndRecordsTheTermsAndThePo(): void
    {
        $method = $this->method();
        $payment = $this->orderPayment([], [NetTermsMethod::INFO_PO_NUMBER => 'PO-77']);
        $method->setInfoInstance($payment);

        $method->validate();

        self::assertSame([true], $this->reads, 'the order is checked against a FRESH read');
        self::assertSame('Net 45', $payment->getAdditionalInformation(NetTermsMethod::INFO_TERMS));
        self::assertSame('45', $payment->getAdditionalInformation(NetTermsMethod::INFO_TERMS_DAYS));
        self::assertSame('PO-77', $payment->getData('po_number'));
    }

    public function testPlacingAnOrderOverTheAvailableCreditIsRefused(): void
    {
        $method = $this->method();
        $payment = $this->orderPayment(['grand_total' => '600.00']);
        $method->setInfoInstance($payment);

        try {
            $method->validate();
            self::fail('expected a refusal');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('more than the net-terms credit you have available', $e->getMessage());
        }
        self::assertNull($payment->getAdditionalInformation(NetTermsMethod::INFO_TERMS));
    }

    public function testAGuestOrderIsRefused(): void
    {
        $method = $this->method();
        $method->setInfoInstance($this->orderPayment(['customer_id' => null, 'customer_is_guest' => 1]));

        $this->expectException(LocalizedException::class);
        $method->validate();
    }

    public function testThePoNumberIsCleanedCappedAndOnlyKeptWhenSwitchedOn(): void
    {
        $method = $this->method();
        $payment = $this->orderPayment([]);
        $method->setInfoInstance($payment);

        $method->assignData(new DataObject(['po_number' => ' <b>PO</b>   ' . str_repeat('9', 80)]));
        self::assertSame(NetTermsMethod::PO_MAX_LENGTH, mb_strlen((string) $payment->getData('po_number')));
        self::assertStringStartsWith('PO 999', (string) $payment->getData('po_number'));

        $this->poEnabled = false;
        $method->assignData(new DataObject(['po_number' => 'PO-1']));
        self::assertNull($payment->getData('po_number'));
        self::assertSame('', $payment->getAdditionalInformation(NetTermsMethod::INFO_PO_NUMBER));
    }
}
