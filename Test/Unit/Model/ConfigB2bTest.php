<?php
/**
 * The B2B display switches: raw state for the dashboard, gated state for the storefront.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Config
 */
class ConfigB2bTest extends TestCase
{
    private const PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const PATH_GROUPS = 'tackquote_quotes/b2b/customer_groups';

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');
    }

    /**
     * @param array<string, bool> $flags
     * @param array<string, mixed> $values
     * @return Config
     */
    private function config(array $flags, array $values = []): Config
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static function ($path) use ($flags) {
                return $flags[$path] ?? false;
            }
        );
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function ($path) use ($values) {
                return $values[$path] ?? null;
            }
        );

        return new Config($this->scopeConfig, $this->encryptor);
    }

    private static function path(string $feature): string
    {
        return 'tackquote_quotes/b2b/' . $feature;
    }

    public function testEachSwitchReadsItsOwnConfigPath(): void
    {
        foreach (Config::B2B_FEATURES as $feature) {
            $config = new Config($this->createScopeConfigWithOnly(self::path($feature)), $this->encryptor);
            foreach (Config::B2B_FEATURES as $other) {
                self::assertSame(
                    $other === $feature,
                    $config->isB2bSwitchOn($other),
                    "$other must read tackquote_quotes/b2b/$other and nothing else"
                );
            }
        }
    }

    /**
     * @param string $onlyPath
     * @return ScopeConfigInterface
     */
    private function createScopeConfigWithOnly(string $onlyPath): ScopeConfigInterface
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static function ($path) use ($onlyPath) {
                return $path === $onlyPath;
            }
        );

        return $scope;
    }

    public function testTheFourFeatureNamesMatchTheSystemXmlFieldIds(): void
    {
        self::assertSame(
            ['enable_account_price', 'enable_quantity_breaks', 'enable_order_limits', 'enable_buyer_group_badge'],
            Config::B2B_FEATURES
        );
    }

    public function testAnUnknownFeatureIsOffWithoutReadingConfig(): void
    {
        $this->scopeConfig->expects($this->never())->method('isSetFlag');
        $config = new Config($this->scopeConfig, $this->encryptor);

        self::assertFalse($config->isB2bSwitchOn('enable_everything'));
    }

    public function testASwitchedOnFeatureIsNotShownWithoutAnApiKey(): void
    {
        $config = $this->config(
            [self::PATH_ENABLED => true, self::path(Config::B2B_ACCOUNT_PRICE) => true],
            [self::PATH_API_KEY => '']
        );

        self::assertTrue($config->isB2bSwitchOn(Config::B2B_ACCOUNT_PRICE), 'the dashboard still reports it');
        self::assertFalse($config->isB2bFeatureEnabled(Config::B2B_ACCOUNT_PRICE));
        self::assertSame([], $config->getEnabledB2bFeatures());
    }

    public function testASwitchedOnFeatureIsNotShownWhenTheModuleIsDisabled(): void
    {
        $config = $this->config(
            [self::PATH_ENABLED => false, self::path(Config::B2B_BUYER_GROUP) => true],
            [self::PATH_API_KEY => '0:3:abc']
        );

        self::assertFalse($config->isB2bFeatureEnabled(Config::B2B_BUYER_GROUP));
        self::assertSame([], $config->getEnabledB2bFeatures());
    }

    public function testEnabledFeaturesAreListedInDeclarationOrder(): void
    {
        $config = $this->config(
            [
                self::PATH_ENABLED => true,
                self::path(Config::B2B_BUYER_GROUP) => true,
                self::path(Config::B2B_QUANTITY_BREAKS) => true,
            ],
            [self::PATH_API_KEY => '0:3:abc']
        );

        self::assertSame(
            [Config::B2B_QUANTITY_BREAKS, Config::B2B_BUYER_GROUP],
            $config->getEnabledB2bFeatures()
        );
        self::assertTrue($config->isB2bFeatureEnabled(Config::B2B_BUYER_GROUP));
        self::assertFalse($config->isB2bFeatureEnabled(Config::B2B_ORDER_LIMITS));
    }

    public function testEverySwitchIsOffWhenNothingIsStored(): void
    {
        $config = $this->config([self::PATH_ENABLED => true], [self::PATH_API_KEY => '0:3:abc']);

        foreach (Config::B2B_FEATURES as $feature) {
            self::assertFalse($config->isB2bSwitchOn($feature), "$feature must default to off");
        }
    }

    public function testCustomerGroupsAreReturnedRaw(): void
    {
        $config = $this->config([], [self::PATH_GROUPS => '1,3']);

        self::assertSame('1,3', $config->getB2bCustomerGroups());
    }

    public function testConfigXmlShipsEverySwitchOff(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        foreach (Config::B2B_FEATURES as $feature) {
            $node = $xml->xpath('/config/default/tackquote_quotes/b2b/' . $feature);
            self::assertNotEmpty($node, "etc/config.xml must declare a default for $feature");
            self::assertSame('0', trim((string) $node[0]), "$feature must default to 0");
        }
    }
}
