<?php
/**
 * The quote-checkout switch (lane MG-QUOTE-CHECKOUT-LINK): its path and the connection
 * requirement. Harness copied from ConfigEnforceTest.
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
class ConfigQuoteCheckoutTest extends TestCase
{
    private const PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const PATH_QUOTE_CHECKOUT = 'tackquote_quotes/quote_checkout/enabled';

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

    public function testTheSwitchActsOnlyWithAWorkingConnection(): void
    {
        self::assertTrue($this->config([
            self::PATH_ENABLED => true,
            self::PATH_QUOTE_CHECKOUT => true
        ])->isQuoteCheckoutActive());

        $off = $this->config([self::PATH_ENABLED => true]);
        self::assertFalse($off->isQuoteCheckoutEnabled());
        self::assertFalse($off->isQuoteCheckoutActive());

        $disabled = $this->config([self::PATH_QUOTE_CHECKOUT => true]);
        self::assertTrue($disabled->isQuoteCheckoutEnabled(), 'the raw switch');
        self::assertFalse($disabled->isQuoteCheckoutActive());

        $noKey = $this->config([self::PATH_ENABLED => true, self::PATH_QUOTE_CHECKOUT => true], '');
        self::assertFalse($noKey->isQuoteCheckoutActive());
    }
}
