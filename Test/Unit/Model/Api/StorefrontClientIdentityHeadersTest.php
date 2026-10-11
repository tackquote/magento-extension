<?php
/**
 * A /storefront/v1 read may carry the Magento customer namespace headers, and nothing else a
 * caller passes: no second credential can be smuggled in.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Api\StorefrontClient
 */
class StorefrontClientIdentityHeadersTest extends TestCase
{
    /**
     * @param array<string, string> $identityHeaders
     * @return array<string, string> The headers that went out.
     */
    private function sentHeaders(array $identityHeaders): array
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('tq_key');
        $config->method('getApiBaseUrl')->willReturn('https://api.tackquote.test/v1');

        $sent = [];
        $curl = $this->createMock(Curl::class);
        $curl->method('setHeaders')->willReturnCallback(static function ($headers) use (&$sent): void {
            $sent = $headers;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"status":"none"}');
        $factory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($curl);

        (new StorefrontClient($config, $factory, new Json(), $this->createMock(LoggerInterface::class)))
            ->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => 'b@example.test'], 1, $identityHeaders);

        return $sent;
    }

    public function testTheNamespaceHeadersGoOutBesideTheKey(): void
    {
        $sent = $this->sentHeaders([
            'X-TackQuote-Platform' => 'magento',
            'X-TackQuote-Store-Id' => '0b9f6c1e-3a52-4d0e-9a0f-5c1d2e3f4a5b',
        ]);

        self::assertSame('magento', $sent['X-TackQuote-Platform'] ?? null);
        self::assertSame('0b9f6c1e-3a52-4d0e-9a0f-5c1d2e3f4a5b', $sent['X-TackQuote-Store-Id'] ?? null);
        self::assertSame('tq_key', $sent['X-Api-Key'] ?? null);
    }

    public function testAnythingElseIsDropped(): void
    {
        $sent = $this->sentHeaders([
            'Authorization' => 'Bearer other',
            'X-Api-Key' => 'someone_elses',
            'X-TackQuote-Platform' => "magento\r\nX-Evil: 1",
        ]);

        self::assertArrayNotHasKey('Authorization', $sent);
        self::assertArrayNotHasKey('X-TackQuote-Platform', $sent);
        self::assertSame('tq_key', $sent['X-Api-Key'] ?? null);
    }

    public function testNoIdentityHeadersSendsExactlyWhatItAlwaysSent(): void
    {
        self::assertSame(
            ['X-Api-Key' => 'tq_key', 'Accept' => 'application/json', 'User-Agent' => 'TackQuote-Magento2'],
            $this->sentHeaders([])
        );
    }
}
