<?php
/**
 * The two URL decisions of "Connect with TackQuote": may this store use it
 * (https, http only on a development host), and may the admin's browser be
 * sent to the consent URL TackQuote answered with.
 *
 * Mutants run (listed in the PR): accept any scheme, skip the default-host rule,
 * skip the request-id compare.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Connect\ConnectUrlPolicy;

/**
 * @covers \TackQuote\Quotes\Model\Connect\ConnectUrlPolicy
 */
class ConnectUrlPolicyTest extends TestCase
{
    private const REQ = 'mgr_AAAAAAAAAAAAAAAAAAAAAA';
    private const DEFAULT_API = 'https://api.tackquote.com/v1';

    /**
     * @dataProvider storeUrls
     */
    public function testIsAllowedUrl(string $url, bool $expected): void
    {
        $this->assertSame($expected, ConnectUrlPolicy::isAllowedUrl($url));
    }

    public static function storeUrls(): array
    {
        return [
            'https public' => ['https://shop.example.com/', true],
            'http public' => ['http://shop.example.com/', false],
            'http localhost' => ['http://localhost/', true],
            'http single label' => ['http://magento/', true],
            'http .test' => ['http://magento.test/', true],
            'http private ip' => ['http://192.168.1.20/', true],
            'http public ip' => ['http://8.8.8.8/', false],
            'ftp' => ['ftp://shop.example.com/', false],
            'garbage' => ['not a url', false],
        ];
    }

    public function testConsentHostAcceptsTheConsentPageForThisRequest(): void
    {
        $this->assertSame(
            'app.tackquote.com',
            ConnectUrlPolicy::consentHost(
                'https://app.tackquote.com/auth/connect/magento?request=' . self::REQ,
                self::REQ,
                self::DEFAULT_API
            )
        );
    }

    /**
     * @dataProvider refusedConsentUrls
     */
    public function testConsentHostRefuses(string $url, string $requestId, string $api): void
    {
        $this->assertSame('', ConnectUrlPolicy::consentHost($url, $requestId, $api));
    }

    public static function refusedConsentUrls(): array
    {
        $ok = 'https://app.tackquote.com/auth/connect/magento?request=' . self::REQ;

        return [
            'another host for the default API' => [
                'https://evil.example.net/auth/connect/magento?request=' . self::REQ, self::REQ, self::DEFAULT_API,
            ],
            'plain http' => ['http://app.tackquote.com/auth/connect/magento?request=' . self::REQ, self::REQ, self::DEFAULT_API],
            'the WooCommerce page' => [
                'https://app.tackquote.com/auth/connect/woocommerce?request=' . self::REQ, self::REQ, self::DEFAULT_API,
            ],
            'another request id' => [
                'https://app.tackquote.com/auth/connect/magento?request=mgr_BBBBBBBBBBBBBBBBBBBBBB', self::REQ, self::DEFAULT_API,
            ],
            'a WooCommerce request id' => [
                'https://app.tackquote.com/auth/connect/magento?request=wcr_AAAAAAAAAAAAAAAAAAAAAA',
                'wcr_AAAAAAAAAAAAAAAAAAAAAA',
                self::DEFAULT_API,
            ],
            'userinfo' => ['https://u:p@app.tackquote.com/auth/connect/magento?request=' . self::REQ, self::REQ, self::DEFAULT_API],
            'fragment' => [$ok . '#x', self::REQ, self::DEFAULT_API],
            'empty' => ['', self::REQ, self::DEFAULT_API],
        ];
    }

    public function testACustomApiBaseUrlMayAnswerWithItsOwnHttpsHost(): void
    {
        $this->assertSame(
            'app.staging.example.com',
            ConnectUrlPolicy::consentHost(
                'https://app.staging.example.com/auth/connect/magento?request=' . self::REQ,
                self::REQ,
                'https://api.staging.example.com/v1'
            )
        );
    }
}
