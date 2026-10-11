<?php
/**
 * How the saved key arrived (Model\Config::getConnectedVia): "connect" only while
 * the saved default-scope key is still the one Connect saved.
 *
 * Mutant run (listed in the PR): drop the fingerprint compare (a pasted key
 * would then read as Connect, and Disconnect would revoke it).
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
class ConfigConnectTest extends TestCase
{
    private const KEY = 'tack_sk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function config(array $values): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function ($path) use ($values) {
            return $values[$path] ?? null;
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function ($v): string {
            return strpos((string) $v, 'ENC:') === 0 ? substr((string) $v, 4) : '';
        });

        return new Config($scope, $encryptor);
    }

    public function testConnectWhileTheSavedKeyIsTheConnectOne(): void
    {
        $config = $this->config([
            'tackquote_quotes/general/api_key' => 'ENC:' . self::KEY,
            Config::XML_PATH_CONNECTED_VIA => Config::VIA_CONNECT,
            Config::XML_PATH_CONNECTED_KEY_SHA256 => hash('sha256', self::KEY),
        ]);
        $this->assertSame(Config::VIA_CONNECT, $config->getConnectedVia());
        $this->assertSame(self::KEY, $config->getDefaultApiKey());
    }

    public function testAKeyPastedAfterConnectReadsAsPasted(): void
    {
        $config = $this->config([
            'tackquote_quotes/general/api_key' => 'ENC:tack_sk_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            Config::XML_PATH_CONNECTED_VIA => Config::VIA_CONNECT,
            Config::XML_PATH_CONNECTED_KEY_SHA256 => hash('sha256', self::KEY),
        ]);
        $this->assertSame(Config::VIA_KEY, $config->getConnectedVia());
    }

    public function testAKeyWithNoRecordReadsAsPasted(): void
    {
        $config = $this->config(['tackquote_quotes/general/api_key' => 'ENC:' . self::KEY]);
        $this->assertSame(Config::VIA_KEY, $config->getConnectedVia());
    }

    public function testNoKeyIsNotConnected(): void
    {
        $config = $this->config([
            Config::XML_PATH_CONNECTED_VIA => Config::VIA_CONNECT,
            Config::XML_PATH_CONNECTED_KEY_SHA256 => hash('sha256', self::KEY),
        ]);
        $this->assertSame('', $config->getConnectedVia());
    }
}
