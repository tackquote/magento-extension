<?php
/**
 * The page-cache vary value for "except approved wholesale accounts". Without it, the page
 * built for one signed-in customer would be served to another in the same group.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\PageCache;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\PageCache\WholesaleApprovalContext;

/**
 * @covers \TackQuote\Quotes\Plugin\PageCache\WholesaleApprovalContext
 */
class WholesaleApprovalContextTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var HttpContext&MockObject */
    private $context;

    /** @var array<string, array{0: mixed, 1: mixed}> name => [value, default] */
    private $set = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->context = $this->createMock(HttpContext::class);
        $this->context->method('setValue')->willReturnCallback(function ($name, $value, $default) {
            $this->set[$name] = [$value, $default];

            return $this->context;
        });
    }

    private function storeUsing(string $scope, bool $configured = true, bool $enabled = true): void
    {
        $this->config->method('isConfigured')->willReturn($configured);
        $this->config->method('isQuoteOnlyEnabled')->willReturn($enabled);
        $this->config->method('getQuoteOnlyScope')->willReturn($scope);
    }

    private function signedIn(bool $signedIn): void
    {
        $this->context->method('getValue')->willReturnCallback(static function ($name) use ($signedIn) {
            return $name === CustomerContext::CONTEXT_AUTH ? $signedIn : null;
        });
    }

    private function fire(): void
    {
        (new WholesaleApprovalContext($this->config, $this->mode))->beforeGetVaryString($this->context);
    }

    public function testAnApprovedCustomerGetsTheirOwnCacheVariation(): void
    {
        $this->storeUsing('unapproved');
        $this->signedIn(true);
        $this->mode->method('isApprovedWholesale')->willReturn(true);

        $this->fire();

        self::assertSame([1, 0], $this->set[WholesaleApprovalContext::CONTEXT_KEY] ?? null);
    }

    public function testAnUnapprovedCustomerSharesTheDefaultVariation(): void
    {
        // Value == default, so Http\Context::getData() leaves it out of the vary string and
        // the unapproved customer shares the cache with every other customer of the group.
        $this->storeUsing('unapproved');
        $this->signedIn(true);
        $this->mode->method('isApprovedWholesale')->willReturn(false);

        $this->fire();

        self::assertSame([0, 0], $this->set[WholesaleApprovalContext::CONTEXT_KEY] ?? null);
    }

    public function testAGuestIsNeverLookedUpAndNeverStartsASession(): void
    {
        $this->storeUsing('unapproved');
        $this->signedIn(false);
        $this->mode->expects(self::never())->method('isApprovedWholesale');

        $this->fire();

        self::assertSame([], $this->set);
    }

    /**
     * @dataProvider otherStores
     */
    public function testNoOtherStoreAddsAVaryValue(string $scope, bool $configured, bool $enabled): void
    {
        $this->storeUsing($scope, $configured, $enabled);
        $this->signedIn(true);
        $this->mode->expects(self::never())->method('isApprovedWholesale');

        $this->fire();

        self::assertSame([], $this->set);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function otherStores(): array
    {
        return [
            'scope guests' => ['guests', true, true],
            'scope everyone' => ['all', true, true],
            'scope groups' => ['groups', true, true],
            'mode off' => ['unapproved', true, false],
            'no API key' => ['unapproved', false, true],
        ];
    }
}
