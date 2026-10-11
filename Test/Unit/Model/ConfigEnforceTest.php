<?php
/**
 * The B2B checkout switches: their paths, the connection requirement, and the tax setting read.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Config
 */
class ConfigEnforceTest extends TestCase
{
    private const PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const PATH_PRICES = 'tackquote_quotes/b2b_checkout/enforce_prices';
    private const PATH_LIMITS = 'tackquote_quotes/b2b_checkout/enforce_order_limits';
    private const PATH_TAX = 'tax/calculation/price_includes_tax';

    /**
     * @param array<string, bool> $flags
     */
    private function config(array $flags, string $key = 'stored'): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static function ($path) use ($flags) {
            return $flags[$path] ?? false;
        });
        $scope->method('getValue')->willReturnCallback(static function ($path) use ($key) {
            return $path === self::PATH_API_KEY ? $key : null;
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn($key === '' ? '' : 'tq_live_key');

        return new Config($scope, $encryptor);
    }

    public function testBothSwitchesAreOffUnlessSet(): void
    {
        $config = $this->config([self::PATH_ENABLED => true]);

        self::assertFalse($config->isEnforcePricesSwitchOn());
        self::assertFalse($config->isEnforceOrderLimitsSwitchOn());
        self::assertFalse($config->isEnforcePricesEnabled());
        self::assertFalse($config->isEnforceOrderLimitsEnabled());
    }

    public function testASwitchActsOnlyWithAWorkingConnection(): void
    {
        $on = [self::PATH_PRICES => true, self::PATH_LIMITS => true];

        $live = $this->config($on + [self::PATH_ENABLED => true]);
        self::assertTrue($live->isEnforcePricesEnabled());
        self::assertTrue($live->isEnforceOrderLimitsEnabled());

        $disabled = $this->config($on);
        self::assertTrue($disabled->isEnforcePricesSwitchOn(), 'the raw switch, for the dashboard');
        self::assertFalse($disabled->isEnforcePricesEnabled());
        self::assertFalse($disabled->isEnforceOrderLimitsEnabled());

        $noKey = $this->config($on + [self::PATH_ENABLED => true], '');
        self::assertFalse($noKey->isEnforcePricesEnabled());
    }

    public function testTheTaxSettingIsMagentosOwnPath(): void
    {
        self::assertTrue($this->config([self::PATH_TAX => true])->catalogPricesIncludeTax());
        self::assertFalse($this->config([])->catalogPricesIncludeTax());
    }
}
