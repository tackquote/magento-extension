<?php
/**
 * The two server-to-server calls: X-Api-Key only, a fresh client per call, the token checked
 * before any I/O and never logged, and the portal link read only from a 404/410.
 *
 * Mutation proof:
 *   C1 send the key in Authorization too        -> testOnlyXApiKeyIsSent
 *   C2 skip the token shape check               -> testAMalformedTokenNeverReachesTheNetwork
 *   C3 accept an http portal link               -> testThePortalLinkIsHttpsOnly
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteCheckout\CheckoutApiClient;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\CheckoutApiClient
 */
class CheckoutApiClientTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJ0123456';

    /** @var array<int, array{method: string, url: string, body: ?string, headers: array}> */
    private $calls = [];

    /** @var string[] */
    private $logged = [];

    /** @var CurlFactory&MockObject */
    private $factory;

    private function client(int $status, string $body, string $key = 'tq_live_key'): CheckoutApiClient
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn($key);
        $config->method('getApiBaseUrl')->willReturn('https://api.tackquote.test/v1');

        $this->factory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->factory->method('create')->willReturnCallback(function () use ($status, $body) {
            $index = count($this->calls);
            $this->calls[$index] = ['method' => '', 'url' => '', 'body' => null, 'headers' => []];
            $curl = $this->createMock(Curl::class);
            $curl->method('setHeaders')->willReturnCallback(function ($headers) use ($index): void {
                $this->calls[$index]['headers'] = $headers;
            });
            $curl->method('get')->willReturnCallback(function ($url) use ($index): void {
                $this->calls[$index]['method'] = 'GET';
                $this->calls[$index]['url'] = $url;
            });
            $curl->method('post')->willReturnCallback(function ($url, $payload) use ($index): void {
                $this->calls[$index]['method'] = 'POST';
                $this->calls[$index]['url'] = $url;
                $this->calls[$index]['body'] = $payload;
            });
            $curl->method('getStatus')->willReturn($status);
            $curl->method('getBody')->willReturn($body);

            return $curl;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->logged[] = (string) $message;
        });

        return new CheckoutApiClient($config, $this->factory, new Json(), $logger);
    }

    public function testTheExchangeIsAGetOfTheTokenRoute(): void
    {
        $out = $this->client(200, '{"quoteRef":"tqco_AAAAAAAAAAAAAAAAAAAAAA","lines":[]}')->exchange(self::TOKEN);

        self::assertTrue($out['ok']);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame(
            'https://api.tackquote.test/v1/integrations/magento/quote-checkout/' . self::TOKEN,
            $this->calls[0]['url']
        );
    }

    public function testOnlyXApiKeyIsSent(): void
    {
        $this->client(200, '{}')->exchange(self::TOKEN);
        $this->client(200, '{}')->complete(['quoteRef' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA', 'orderId' => '1', 'incrementId' => '1']);

        foreach ($this->calls as $call) {
            self::assertSame('tq_live_key', $call['headers']['X-Api-Key'] ?? null);
            self::assertArrayNotHasKey('Authorization', $call['headers']);
        }
    }

    public function testAMalformedTokenNeverReachesTheNetwork(): void
    {
        $client = $this->client(200, '{}');
        $this->factory->expects(self::never())->method('create');

        self::assertSame(['ok' => false, 'status' => 0], $client->exchange('../../etc/passwd'));
        self::assertSame(['ok' => false, 'status' => 0], $client->exchange(self::TOKEN . 'x'));
    }

    public function testAnUnconfiguredStoreMakesNoCall(): void
    {
        $out = $this->client(200, '{}', '')->exchange(self::TOKEN);

        self::assertFalse($out['ok']);
        self::assertSame([], $this->calls);
    }

    public function testASpentLinkCarriesThePortalLinkAndTheTokenIsNeverLogged(): void
    {
        $out = $this->client(410, '{"statusCode":410,"message":"used","portalUrl":"https://portal.example.test/login"}')
            ->exchange(self::TOKEN);

        self::assertFalse($out['ok']);
        self::assertSame(410, $out['status']);
        self::assertSame('https://portal.example.test/login', $out['portalUrl']);
        self::assertArrayNotHasKey('data', $out);
        foreach ($this->logged as $line) {
            self::assertStringNotContainsString(self::TOKEN, $line);
            self::assertStringNotContainsString('tq_live_key', $line);
        }
        self::assertNotEmpty($this->logged);
    }

    public function testThePortalLinkIsHttpsOnly(): void
    {
        $out = $this->client(404, '{"portalUrl":"javascript:alert(1)"}')->exchange(self::TOKEN);
        self::assertArrayNotHasKey('portalUrl', $out);
        $out = $this->client(404, '{"portalUrl":"http://portal.example.test/login"}')->exchange(self::TOKEN);
        self::assertArrayNotHasKey('portalUrl', $out);
    }

    public function testCompletionPostsTheReportAsJson(): void
    {
        $report = [
            'quoteRef' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
            'orderId' => '901',
            'incrementId' => '000000901',
            'state' => 'processing',
            'paymentMethod' => 'checkmo',
        ];
        $out = $this->client(200, '{"linked":true}')->complete($report);

        self::assertTrue($out['ok']);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://api.tackquote.test/v1/integrations/magento/quote-checkout/complete', $this->calls[0]['url']);
        self::assertSame($report, json_decode((string) $this->calls[0]['body'], true));
        self::assertSame('application/json', $this->calls[0]['headers']['Content-Type'] ?? null);
    }
}
