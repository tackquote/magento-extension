<?php
/**
 * The Connect callback URL carries the Admin secret key, whatever order the URLs are built in.
 *
 * The backend URL model below emulates Magento\Backend\Model\Url as read in 2.4.8-p5: the
 * `noSecret` flag is changed only when a params ARRAY is passed (isset($params['_nosecret'])
 * turns it on, any other array turns it off) and otherwise STICKS on the shared instance;
 * turnOnSecretKey() clears it. With the flag stuck, the callback lost its `key/<secret>/`
 * segment and Magento bounced TackQuote's return to the dashboard (devstore Q/A finding #25).
 *
 * Mutations (applied, red, restored):
 *   K1  build adminUrl before redirectUri and drop the first turnOnSecretKey() ->
 *       testTheCallbackCarriesTheSecretKeyAndTheDashboardLinkDoesNot
 *   K2  drop the second turnOnSecretKey() -> testTheKeylessSwitchDoesNotLeakIntoTheRestOfTheRequest
 *   K3  pass ['_nosecret' => false] to the callback instead of turnOnSecretKey() ->
 *       testTheCallbackCarriesTheSecretKeyEvenWhenTheFlagWasAlreadyStuck
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Connect\StoreInfo;

/**
 * @covers \TackQuote\Quotes\Model\Connect\StoreInfo
 */
class StoreInfoTest extends TestCase
{
    private const ADMIN = 'https://store.example.test/admin/';
    private const KEY = 'a1b2c3d4e5f6a7b8c9d0';

    /** @var bool The emulated shared `noSecret` flag. */
    private $noSecret = false;

    /** @var BackendUrl */
    private $url;

    private function storeInfo(): StoreInfo
    {
        $this->url = $this->createMock(BackendUrl::class);
        $this->url->method('getUrl')->willReturnCallback(function ($path = null, $params = null): string {
            if (is_array($params)) {
                $this->noSecret = isset($params['_nosecret']);
            }

            return self::ADMIN . trim((string) $path, '/') . '/' . ($this->noSecret ? '' : 'key/' . self::KEY . '/');
        });
        $this->url->method('turnOnSecretKey')->willReturnCallback(function () {
            $this->noSecret = false;

            return $this->url;
        });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');
        $modules = $this->createMock(ModuleListInterface::class);
        $modules->method('getOne')->willReturn(['setup_version' => '1.0.0']);

        return new StoreInfo(
            $scopeConfig,
            $this->url,
            $this->createMock(ProductMetadataInterface::class),
            $modules
        );
    }

    public function testTheCallbackCarriesTheSecretKeyAndTheDashboardLinkDoesNot(): void
    {
        $info = $this->storeInfo()->describe();

        self::assertSame(self::ADMIN . 'tackquote/connect/callback/key/' . self::KEY . '/', $info['redirectUri']);
        self::assertSame(self::ADMIN . 'tackquote/dashboard/index/', $info['adminUrl']);
    }

    public function testTheCallbackCarriesTheSecretKeyEvenWhenTheFlagWasAlreadyStuck(): void
    {
        $info = $this->storeInfo();
        // Something earlier in the request built a key-less URL on the shared instance.
        $this->url->getUrl('adminhtml/dashboard', ['_nosecret' => true]);

        self::assertStringEndsWith('/callback/key/' . self::KEY . '/', $info->describe()['redirectUri']);
    }

    public function testTheKeylessSwitchDoesNotLeakIntoTheRestOfTheRequest(): void
    {
        $info = $this->storeInfo();
        $info->describe();

        self::assertStringEndsWith('/key/' . self::KEY . '/', $this->url->getUrl('tackquote/dashboard/index'));
    }
}
