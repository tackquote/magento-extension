<?php
/**
 * What the ping tells TackQuote about the landing route.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteCheckout\LandingUrl;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\LandingUrl
 */
class LandingUrlTest extends TestCase
{
    private function landing(bool $active, string $base): LandingUrl
    {
        $config = $this->createMock(Config::class);
        $config->method('isQuoteCheckoutActive')->willReturn($active);
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_LINK, true)->willReturn($base);
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);

        return new LandingUrl($config, $manager);
    }

    public function testTheStoreViewsOwnLinkUrlWithTheRoute(): void
    {
        self::assertSame(
            'https://shop.example.test/default/tackquote/checkout/start',
            $this->landing(true, 'https://shop.example.test/default/')->headerValue(1)
        );
        self::assertSame(
            'https://shop.example.test/index.php/tackquote/checkout/start',
            $this->landing(true, 'https://shop.example.test/index.php/')->headerValue(1)
        );
    }

    public function testOffWhenSwitchedOffOrNotHttps(): void
    {
        self::assertSame('off', $this->landing(false, 'https://shop.example.test/')->headerValue(1));
        self::assertSame('off', $this->landing(true, 'http://shop.example.test/')->headerValue(1));
    }
}
