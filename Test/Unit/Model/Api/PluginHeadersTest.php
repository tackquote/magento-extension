<?php
/**
 * X-TackQuote-Plugin-Version and X-TackQuote-Site-Url: the values, and what is left out.
 *
 * Mirrors the WooCommerce plugin, which sends both on every request
 * (tackquote-woocommerce includes/class-tack-api-client.php, request()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Api\PluginHeaders;

/**
 * @covers \TackQuote\Quotes\Model\Api\PluginHeaders
 */
class PluginHeadersTest extends TestCase
{
    /**
     * @param mixed $module What ModuleListInterface::getOne() answers.
     * @param string|\Exception $baseUrl The store's base URL, or what getStore() throws.
     * @param int|null $expectStoreId The store id getStore() must be asked for.
     * @return PluginHeaders
     */
    private function headers($module, $baseUrl, ?int $expectStoreId = 3): PluginHeaders
    {
        $modules = $this->createMock(ModuleListInterface::class);
        $modules->method('getOne')->with('TackQuote_Quotes')->willReturn($module);

        $stores = $this->createMock(StoreManagerInterface::class);
        if ($baseUrl instanceof \Exception) {
            $stores->method('getStore')->willThrowException($baseUrl);
        } else {
            $store = $this->getMockBuilder(Store::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getBaseUrl'])
                ->getMock();
            $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)->willReturn($baseUrl);
            $stores->expects($this->once())->method('getStore')->with($expectStoreId)->willReturn($store);
        }

        return new PluginHeaders($modules, $stores);
    }

    public function testBothHeadersFromTheModuleVersionAndTheStoreBaseUrl(): void
    {
        self::assertSame(
            [
                'X-TackQuote-Plugin-Version' => '1.4.1',
                'X-TackQuote-Site-Url' => 'https://shop.example.test/de',
            ],
            $this->headers(['name' => 'TackQuote_Quotes', 'setup_version' => '1.4.1'], 'https://shop.example.test/de/')
                ->get(3)
        );
    }

    public function testThePreReleaseShapeIsSent(): void
    {
        self::assertSame(
            '2.0.0-rc.1',
            $this->headers(['setup_version' => '2.0.0-rc.1'], 'https://shop.example.test/')
                ->get(3)['X-TackQuote-Plugin-Version'] ?? null
        );
    }

    /**
     * @dataProvider notAVersion
     * @param mixed $module
     */
    public function testAVersionThatIsNotOneIsLeftOut($module): void
    {
        $sent = $this->headers($module, 'https://shop.example.test/')->get(3);

        self::assertArrayNotHasKey('X-TackQuote-Plugin-Version', $sent);
        self::assertSame('https://shop.example.test', $sent['X-TackQuote-Site-Url'] ?? null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAVersion(): array
    {
        return [
            'module unknown' => [null],
            'no setup_version' => [['name' => 'TackQuote_Quotes']],
            'empty' => [['setup_version' => '']],
            'two parts' => [['setup_version' => '1.4']],
            'header injection' => [['setup_version' => "1.4.1\r\nX-Evil: 1"]],
            'too long' => [['setup_version' => '1.4.1-' . str_repeat('a', 40)]],
        ];
    }

    /**
     * @dataProvider notASiteUrl
     * @param string|\Exception $baseUrl
     */
    public function testASiteUrlThatCannotBeSentIsLeftOut($baseUrl): void
    {
        $sent = $this->headers(['setup_version' => '1.4.1'], $baseUrl)->get(3);

        self::assertArrayNotHasKey('X-TackQuote-Site-Url', $sent);
        self::assertSame('1.4.1', $sent['X-TackQuote-Plugin-Version'] ?? null);
    }

    /**
     * @return array<string, array{0: string|\Exception}>
     */
    public static function notASiteUrl(): array
    {
        return [
            'store missing' => [new NoSuchEntityException(__('no store'))],
            'empty' => [''],
            'relative' => ['/shop/'],
            'other scheme' => ['ftp://shop.example.test/'],
            'header injection' => ["https://shop.example.test/\r\nX-Evil: 1"],
            'space' => ['https://shop.example.test/a b/'],
            'too long' => ['https://shop.example.test/' . str_repeat('a', 2100)],
        ];
    }

    public function testNoStoreIdAsksForTheCurrentStore(): void
    {
        self::assertSame(
            'http://localhost:8080',
            $this->headers(['setup_version' => '1.4.1'], 'http://localhost:8080/', null)
                ->get()['X-TackQuote-Site-Url'] ?? null
        );
    }

    public function testNeitherHeaderIsACredential(): void
    {
        $sent = $this->headers(['setup_version' => '1.4.1'], 'https://shop.example.test/')->get(3);

        self::assertSame(['X-TackQuote-Plugin-Version', 'X-TackQuote-Site-Url'], array_keys($sent));
    }
}
