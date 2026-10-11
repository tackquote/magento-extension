<?php
/**
 * Who the rules decide for: exempt callers are never asked about, guests need no lookup, the
 * customer-group selection keeps unselected accounts out, and a cart is judged by its own
 * customer.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\Audience;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\BuyerGroupResolver;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;

/**
 * @covers \TackQuote\Quotes\Model\Groups\Audience
 */
class AudienceTest extends TestCase
{
    /** @var VisitorContextInterface&MockObject */
    private $visitor;

    /** @var BuyerGroupResolver&MockObject */
    private $resolver;

    /** @var GroupRulesConfig&MockObject */
    private $config;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    protected function setUp(): void
    {
        $this->visitor = $this->createMock(VisitorContextInterface::class);
        $this->resolver = $this->createMock(BuyerGroupResolver::class);
        $this->config = $this->createMock(GroupRulesConfig::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function audience(): Audience
    {
        return new Audience($this->visitor, $this->resolver, $this->config, $this->storeManager);
    }

    private function signedIn(int $group = 1): void
    {
        $this->visitor->method('isExempt')->willReturn(false);
        $this->visitor->method('isLoggedIn')->willReturn(true);
        $this->visitor->method('getCustomerGroupId')->willReturn($group);
        $this->visitor->method('getCustomerId')->willReturn(42);
        $this->visitor->method('getCustomerEmail')->willReturn('buyer@example.test');
    }

    /**
     * @return CartInterface&MockObject
     */
    private function cart(?int $customerId, int $group = 1, bool $guest = false): CartInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getGroupId')->willReturn($group);
        $customer->method('getEmail')->willReturn(' buyer@example.test ');
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getCustomer')->willReturn($customer);
        $cart->method('getCustomerIsGuest')->willReturn($guest);
        $cart->method('getStoreId')->willReturn(5);

        return $cart;
    }

    public function testAnExemptCallerIsNeverLookedUp(): void
    {
        $this->visitor->method('isExempt')->willReturn(true);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertTrue($this->audience()->current()->isExempt());
        self::assertTrue($this->audience()->forQuote($this->cart(42))->isExempt());
    }

    public function testAGuestNeedsNoLookup(): void
    {
        $this->visitor->method('isExempt')->willReturn(false);
        $this->visitor->method('isLoggedIn')->willReturn(false);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame(BuyerGroupAnswer::GUEST, $this->audience()->current()->getStatus());
    }

    public function testASignedInCustomerIsLookedUpOncePerRequest(): void
    {
        $this->signedIn();
        $this->config->method('getCustomerGroups')->willReturn([]);
        $this->resolver->expects(self::once())->method('resolve')->with(2, 42, 'buyer@example.test')
            ->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));

        $audience = $this->audience();
        self::assertSame('GOLD', $audience->current()->getCode());
        self::assertSame('GOLD', $audience->current()->getCode());
    }

    public function testACustomerOutsideTheSelectedGroupsIsAGuest(): void
    {
        $this->signedIn(1);
        $this->config->method('getCustomerGroups')->willReturn([2, 3]);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame(BuyerGroupAnswer::GUEST, $this->audience()->current()->getStatus());
    }

    public function testACustomerInsideTheSelectedGroupsIsLookedUp(): void
    {
        $this->signedIn(3);
        $this->config->method('getCustomerGroups')->willReturn([2, 3]);
        $this->resolver->method('resolve')->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::NONE));

        self::assertSame(BuyerGroupAnswer::NONE, $this->audience()->current()->getStatus());
    }

    public function testACartIsJudgedByItsOwnCustomerAndStore(): void
    {
        $this->visitor->method('isExempt')->willReturn(false);
        $this->config->method('getCustomerGroups')->willReturn([]);
        $this->resolver->expects(self::once())->method('resolve')->with(5, 77, 'buyer@example.test')
            ->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'TRADE'));

        self::assertSame('TRADE', $this->audience()->forQuote($this->cart(77))->getCode());
    }

    public function testAGuestCartIsAGuest(): void
    {
        $this->visitor->method('isExempt')->willReturn(false);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame(BuyerGroupAnswer::GUEST, $this->audience()->forQuote($this->cart(null))->getStatus());
        self::assertSame(BuyerGroupAnswer::GUEST, $this->audience()->forQuote($this->cart(77, 1, true))->getStatus());
    }

    public function testACartCustomerOutsideTheSelectedGroupsIsAGuest(): void
    {
        $this->visitor->method('isExempt')->willReturn(false);
        $this->config->method('getCustomerGroups')->willReturn([4]);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame(BuyerGroupAnswer::GUEST, $this->audience()->forQuote($this->cart(77, 1))->getStatus());
    }
}
