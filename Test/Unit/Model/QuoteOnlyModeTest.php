<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnly\ProductFlag;
use TackQuote\Quotes\Model\QuoteOnly\VisitorContextInterface;
use TackQuote\Quotes\Model\QuoteOnly\WholesaleApproval;
use TackQuote\Quotes\Model\QuoteOnlyMode;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnlyMode
 */
class QuoteOnlyModeTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var VisitorContextInterface&MockObject */
    private $visitor;

    /** @var ProductFlag&MockObject */
    private $flag;

    /** @var WholesaleApproval&MockObject */
    private $approval;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->visitor = $this->createMock(VisitorContextInterface::class);
        $this->flag = $this->createMock(ProductFlag::class);
        $this->approval = $this->createMock(WholesaleApproval::class);
    }

    private function mode(): QuoteOnlyMode
    {
        return new QuoteOnlyMode($this->config, $this->visitor, $this->flag, $this->approval);
    }

    private function withConfig(
        bool $configured,
        bool $enabled,
        string $scope = 'all',
        string $groups = '',
        bool $hidePrices = false
    ): void {
        $this->config->method('isConfigured')->willReturn($configured);
        $this->config->method('isQuoteOnlyEnabled')->willReturn($enabled);
        $this->config->method('getQuoteOnlyScope')->willReturn($scope);
        $this->config->method('getQuoteOnlyCustomerGroups')->willReturn($groups);
        $this->config->method('isQuoteOnlyHidePrices')->willReturn($hidePrices);
    }

    private function asVisitor(bool $loggedIn, int $groupId, bool $exempt = false): void
    {
        $this->visitor->method('isExempt')->willReturn($exempt);
        $this->visitor->method('isLoggedIn')->willReturn($loggedIn);
        $this->visitor->method('getCustomerGroupId')->willReturn($groupId);
        $this->visitor->method('getCustomerId')->willReturn($loggedIn ? 42 : null);
        $this->visitor->method('getCustomerEmail')->willReturn($loggedIn ? 'buyer@example.test' : '');
    }

    public function testItIsActiveForEveryoneByDefault(): void
    {
        $this->withConfig(true, true);
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive());
    }

    public function testItIsInactiveWhenTheMerchantHasNotSwitchedItOn(): void
    {
        $this->withConfig(true, false);
        $this->asVisitor(false, 0);

        self::assertFalse($this->mode()->isActive());
    }

    public function testWithNoApiKeyTheModeStaysINACTIVESoTheCartKeepsWorking(): void
    {
        // THE DEAD-STOREFRONT TEST, enforcement half.
        //
        // Config::isButtonEnabled() and Config::isAddToQuoteEnabled() both require
        // isConfigured(), so on a store with no API key NO quote control renders anywhere.
        // If enforcement did not honour the same condition, a merchant who enabled
        // quote-only before pasting their key would get a storefront with the cart refused
        // AND no quote button: nothing works, in either direction, and nothing in the admin
        // says why.
        $this->withConfig(false, true);
        $this->asVisitor(false, 0);

        self::assertFalse(
            $this->mode()->isActive(),
            'enforcement ran on a store where no quote button can render — that is a shop '
            . 'nobody can buy from AND nobody can request a quote from'
        );
    }

    public function testGuestsOnlyScopeRefusesThePublicAndSparesSignedInCustomers(): void
    {
        $this->withConfig(true, true, 'guests');
        $this->asVisitor(true, 5);

        self::assertFalse($this->mode()->isActive(), 'an approved, signed-in B2B customer keeps their cart');
    }

    public function testGuestsOnlyScopeAppliesToAGuest(): void
    {
        $this->withConfig(true, true, 'guests');
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive());
    }

    public function testSelectedGroupsUsesTheSessionGroupIdVerbatimIncludingZero(): void
    {
        // Magento group 0 is NOT LOGGED IN — a real group. Unlike the OpenCart build of this
        // feature, no substitution of a "default group" is needed or wanted here.
        $this->withConfig(true, true, 'groups', '0,3');
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive(), 'selecting NOT LOGGED IN must match a guest');
    }

    public function testSelectedGroupsSparesAGroupThatWasNotSelected(): void
    {
        $this->withConfig(true, true, 'groups', '3');
        $this->asVisitor(true, 5);

        self::assertFalse($this->mode()->isActive());
    }

    // ── Exempt callers (admin and integrations in the API areas) ─────────────────────────

    public function testAnExemptCallerIsNeverQuoteOnlyEvenWhenTheModeAppliesToEveryone(): void
    {
        // TackQuote converts an accepted quote through the store's REST API as an
        // integration; refusing it would break the very conversion the mode exists for.
        $this->withConfig(true, true);
        $this->asVisitor(false, 0, true);
        $this->flag->method('isQuoteOnly')->willReturn(true);
        $this->flag->method('isQuoteOnlyId')->willReturn(true);

        $mode = $this->mode();
        self::assertFalse($mode->isActive());
        self::assertFalse($mode->isProductQuoteOnly($this->createMock(Product::class)));
        self::assertFalse($mode->isProductIdQuoteOnly(7));
    }

    // ── "Everyone except approved wholesale accounts" ────────────────────────────────────

    public function testUnapprovedScopeSparesAnApprovedSignedInCustomer(): void
    {
        $this->withConfig(true, true, 'unapproved');
        $this->asVisitor(true, 1);
        $this->approval->expects($this->once())->method('isApproved')
            ->with('buyer@example.test', 42, null)->willReturn(true);

        self::assertFalse($this->mode()->isActive(), 'an approved wholesale buyer keeps the cart');
    }

    public function testUnapprovedScopeAppliesToASignedInCustomerTackQuoteHasNotApproved(): void
    {
        // Also what every lookup FAILURE looks like: WholesaleApproval answers false.
        $this->withConfig(true, true, 'unapproved');
        $this->asVisitor(true, 1);
        $this->approval->method('isApproved')->willReturn(false);

        self::assertTrue($this->mode()->isActive(), 'unknown or unapproved must fail CLOSED (quote-only)');
    }

    public function testUnapprovedScopeAppliesToAGuestWithoutAskingTackQuote(): void
    {
        $this->withConfig(true, true, 'unapproved');
        $this->asVisitor(false, 0);
        $this->approval->expects($this->never())->method('isApproved');

        self::assertTrue($this->mode()->isActive());
    }

    public function testOtherScopesNeverAskTackQuote(): void
    {
        $this->withConfig(true, true, 'guests');
        $this->asVisitor(true, 1);
        $this->approval->expects($this->never())->method('isApproved');

        $this->mode()->isActive();
    }

    // ── Per-product ──────────────────────────────────────────────────────────────────────

    public function testAFlaggedProductIsQuoteOnlyWhileTheStoreIsInNormalMode(): void
    {
        $this->withConfig(true, false);
        $this->asVisitor(true, 1);
        $product = $this->createMock(Product::class);
        $this->flag->method('isQuoteOnly')->with($product)->willReturn(true);

        $mode = $this->mode();
        self::assertFalse($mode->isActive());
        self::assertTrue($mode->isProductQuoteOnly($product));
        self::assertTrue($mode->appliesToProduct($product));
    }

    public function testWithNoApiKeyAFlaggedProductStaysPurchasable(): void
    {
        // Dead-product-page guard: no key, no quote button, so no refusal either.
        $this->withConfig(false, false);
        $this->asVisitor(false, 0);
        $this->flag->method('isQuoteOnly')->willReturn(true);
        $this->flag->method('isQuoteOnlyId')->willReturn(true);

        self::assertFalse($this->mode()->isProductQuoteOnly($this->createMock(Product::class)));
        self::assertFalse($this->mode()->isProductIdQuoteOnly(7));
    }

    public function testACartHoldingAFlaggedLineIsDetected(): void
    {
        $this->withConfig(true, false);
        $this->asVisitor(false, 0);
        // Quote items answer getProductId() through DataObject's magic getter, so a plain
        // DataObject is the honest stand-in.
        $plain = new DataObject(['product_id' => 5]);
        $flagged = new DataObject(['product_id' => 7]);
        $this->flag->method('isQuoteOnlyId')->willReturnCallback(static function (int $id): bool {
            return $id === 7;
        });
        $cart = $this->createMock(Quote::class);
        $cart->method('getAllItems')->willReturn([$plain, $flagged]);
        $clean = $this->createMock(Quote::class);
        $clean->method('getAllItems')->willReturn([$plain]);

        $mode = $this->mode();
        self::assertTrue($mode->cartHasQuoteOnlyProduct($cart));
        self::assertTrue($mode->appliesToCart($cart));
        self::assertFalse($mode->cartHasQuoteOnlyProduct($clean));
        self::assertFalse($mode->appliesToCart($clean));
    }

    // ── Price on request ─────────────────────────────────────────────────────────────────

    public function testPricesAreHiddenOnlyWhereTheStoreWideModeAppliesAndTheSwitchIsOn(): void
    {
        $this->withConfig(true, true, 'guests', '', true);
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->shouldHidePrices());
    }

    public function testPricesStayForAVisitorTheModeDoesNotApplyTo(): void
    {
        $this->withConfig(true, true, 'guests', '', true);
        $this->asVisitor(true, 1);

        self::assertFalse($this->mode()->shouldHidePrices());
    }

    public function testPricesStayWhenTheSwitchIsOff(): void
    {
        $this->withConfig(true, true, 'all', '', false);
        $this->asVisitor(false, 0);

        self::assertFalse($this->mode()->shouldHidePrices());
    }
}
