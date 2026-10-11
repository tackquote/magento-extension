<?php
/**
 * Who is exempt in the Web API and GraphQL areas. This is what keeps TackQuote's own quote
 * conversion (an OAuth-signed integration call) working while shoppers are refused.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteOnly;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnly\ApiVisitorContext;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnly\ApiVisitorContext
 */
class ApiVisitorContextTest extends TestCase
{
    /**
     * @param int|null $type
     * @param int|null $id
     */
    private function visitor($type, $id, ?CustomerRepositoryInterface $repository = null): ApiVisitorContext
    {
        $context = $this->createMock(UserContextInterface::class);
        $context->method('getUserType')->willReturn($type);
        $context->method('getUserId')->willReturn($id);

        return new ApiVisitorContext($context, $repository ?? $this->createMock(CustomerRepositoryInterface::class));
    }

    public function testAnIntegrationIsExemptSoTackQuoteQuoteConversionKeepsWorking(): void
    {
        self::assertTrue($this->visitor(UserContextInterface::USER_TYPE_INTEGRATION, 3)->isExempt());
    }

    public function testAnAdminTokenIsExempt(): void
    {
        self::assertTrue($this->visitor(UserContextInterface::USER_TYPE_ADMIN, 1)->isExempt());
    }

    public function testAGuestACustomerAndAnUnknownCallerAreNotExempt(): void
    {
        self::assertFalse($this->visitor(UserContextInterface::USER_TYPE_GUEST, 0)->isExempt());
        self::assertFalse($this->visitor(UserContextInterface::USER_TYPE_CUSTOMER, 5)->isExempt());
        self::assertFalse($this->visitor(null, null)->isExempt(), 'unknown must fail towards the policy');
    }

    public function testAGuestIsGroupZeroWithNoEmail(): void
    {
        $guest = $this->visitor(UserContextInterface::USER_TYPE_GUEST, 0);

        self::assertFalse($guest->isLoggedIn());
        self::assertSame(0, $guest->getCustomerGroupId());
        self::assertNull($guest->getCustomerId());
        self::assertSame('', $guest->getCustomerEmail());
    }

    public function testACustomerTokenReadsGroupAndEmailFromTheCustomerOnce(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(4);
        $customer->method('getEmail')->willReturn('buyer@example.test');
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->expects(self::once())->method('getById')->with(5)->willReturn($customer);

        $visitor = $this->visitor(UserContextInterface::USER_TYPE_CUSTOMER, 5, $repository);

        self::assertTrue($visitor->isLoggedIn());
        self::assertSame(5, $visitor->getCustomerId());
        self::assertSame(4, $visitor->getCustomerGroupId());
        self::assertSame('buyer@example.test', $visitor->getCustomerEmail());
    }

    public function testAnUnreadableCustomerFallsBackToTheGuestGroup(): void
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new \RuntimeException('gone'));

        $visitor = $this->visitor(UserContextInterface::USER_TYPE_CUSTOMER, 5, $repository);

        self::assertSame(0, $visitor->getCustomerGroupId());
        self::assertSame('', $visitor->getCustomerEmail());
    }
}
