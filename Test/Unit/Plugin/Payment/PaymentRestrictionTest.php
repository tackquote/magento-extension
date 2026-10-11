<?php
/**
 * Payment methods by buyer group: the `payment_method_is_active` observer refuses a method the
 * cart's customer may not use, and the MethodList plugin keeps the rules from leaving a cart
 * with no way to pay.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\MethodRestrictions;
use TackQuote\Quotes\Observer\PaymentMethodGroupRestriction;
use TackQuote\Quotes\Plugin\Payment\MethodListRestriction;

/**
 * @covers \TackQuote\Quotes\Observer\PaymentMethodGroupRestriction
 * @covers \TackQuote\Quotes\Plugin\Payment\MethodListRestriction
 */
class PaymentRestrictionTest extends TestCase
{
    private function fire(MethodRestrictions $restrictions, bool $available, ?CartInterface $quote): DataObject
    {
        $result = new DataObject(['is_available' => $available]);
        $method = $this->createMock(MethodInterface::class);
        $method->method('getCode')->willReturn('purchaseorder');
        $event = new Event(['result' => $result, 'method_instance' => $method, 'quote' => $quote]);
        $observer = new Observer(['event' => $event]);

        (new PaymentMethodGroupRestriction($restrictions))->execute($observer);

        return $result;
    }

    public function testARefusedMethodBecomesUnavailable(): void
    {
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->method('isPaymentPermitted')->willReturn(false);

        self::assertFalse($this->fire(
            $restrictions,
            true,
            $this->createMock(CartInterface::class)
        )->getData('is_available'));
    }

    public function testAPermittedMethodStaysAvailable(): void
    {
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->method('isPaymentPermitted')->willReturn(true);

        self::assertTrue($this->fire(
            $restrictions,
            true,
            $this->createMock(CartInterface::class)
        )->getData('is_available'));
    }

    public function testNoCartOrAlreadyUnavailableIsNotAsked(): void
    {
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->expects(self::never())->method('isPaymentPermitted');

        self::assertTrue($this->fire($restrictions, true, null)->getData('is_available'));
        self::assertFalse($this->fire(
            $restrictions,
            false,
            $this->createMock(CartInterface::class)
        )->getData('is_available'));
    }

    public function testAnEmptyListCausedByTheRulesIsReplacedByTheUnrestrictedOne(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $method = $this->createMock(MethodInterface::class);
        $calls = 0;
        $proceed = static function () use (&$calls, $method) {
            return ++$calls === 1 ? [] : [$method];
        };
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->method('hasPaymentRules')->willReturn(true);
        $restrictions->method('isPaymentSuspended')->willReturn(false);
        $restrictions->expects(self::once())->method('suspendPayment')->with($quote);
        $restrictions->expects(self::never())->method('resumePayment');
        $restrictions->expects(self::once())->method('logPaymentSuspended');

        $out = (new MethodListRestriction($restrictions))
            ->aroundGetAvailableMethods($this->createMock(MethodList::class), $proceed, $quote);

        self::assertSame([$method], $out);
    }

    public function testAnEmptyListMagentoWouldGiveAnywayIsKeptAndTheRulesResume(): void
    {
        $quote = $this->createMock(CartInterface::class);
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->method('hasPaymentRules')->willReturn(true);
        $restrictions->expects(self::once())->method('resumePayment')->with($quote);
        $restrictions->expects(self::never())->method('logPaymentSuspended');

        $out = (new MethodListRestriction($restrictions))->aroundGetAvailableMethods(
            $this->createMock(MethodList::class),
            static function () {
                return [];
            },
            $quote
        );

        self::assertSame([], $out);
    }

    public function testANonEmptyListIsUntouched(): void
    {
        $method = $this->createMock(MethodInterface::class);
        $restrictions = $this->createMock(MethodRestrictions::class);
        $restrictions->expects(self::never())->method('suspendPayment');

        $out = (new MethodListRestriction($restrictions))->aroundGetAvailableMethods(
            $this->createMock(MethodList::class),
            static function () use ($method) {
                return [$method];
            },
            $this->createMock(CartInterface::class)
        );

        self::assertSame([$method], $out);
    }
}
