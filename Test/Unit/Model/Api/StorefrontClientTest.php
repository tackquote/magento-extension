<?php
/**
 * The /storefront/v1 read client: the request that goes out, and fail-closed on what
 * comes back.
 *
 * The wire contract is TackQuote's StorefrontV1Controller and its API-key resolver
 * (tack apps/api/src/modules/storefront-api/), which takes the key in X-Api-Key and
 * refuses a request that also carries Authorization. These tests assert on the outgoing
 * request, not only on a mocked answer.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Api\StorefrontClient
 */
class StorefrontClientTest extends TestCase
{
    private const API_BASE = 'https://api.tackquote.test/v1';
    private const KEY = 'tq_live_secret_key';
    private const EMAIL = 'buyer@example.test';

    /** @var Config&MockObject */
    private $config;

    /** @var Curl&MockObject */
    private $curl;

    /** @var CurlFactory&MockObject */
    private $curlFactory;

    /** @var string[] Every log line written, at any level. */
    private $logLines = [];

    /** @var array<string, string> */
    private $headers = [];

    /** @var string[] */
    private $urls = [];

    /** @var array<int, mixed> Bodies handed to Curl::post(). */
    private $posted = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiKey')->willReturn(self::KEY);
        $this->config->method('getApiBaseUrl')->willReturn(self::API_BASE);

        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('setHeaders')->willReturnCallback(function ($headers): void {
            $this->headers = $headers;
        });
        $this->curl->method('addHeader')->willReturnCallback(function ($name, $value): void {
            $this->headers[$name] = $value;
        });
        $this->curl->method('get')->willReturnCallback(function ($url): void {
            $this->urls[] = $url;
        });
        $this->curl->method('post')->willReturnCallback(function ($url, $body): void {
            $this->urls[] = $url;
            $this->posted[] = $body;
        });

        $this->curlFactory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->curlFactory->method('create')->willReturn($this->curl);
    }

    private function client(): StorefrontClient
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['warning', 'error', 'info', 'debug', 'notice', 'critical'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message): void {
                $this->logLines[] = (string) $message;
            });
        }

        return new StorefrontClient($this->config, $this->curlFactory, new Json(), $logger);
    }

    private function respond(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    public function testTheRequestNamesTheRouteSkuBuyerAndCurrencyInTheQuery(): void
    {
        $this->respond(200, '{"status":"priced","unitPrice":9.5,"currency":"USD","quantity":1,"accountSpecific":true}');

        $result = $this->client()->get(
            StorefrontClient::ROUTE_WHOLESALE_PRICE,
            ['buyerEmail' => self::EMAIL, 'sku' => 'AB 12/X', 'currency' => 'USD', 'quantity' => '1']
        );

        self::assertTrue($result['ok']);
        self::assertCount(1, $this->urls);
        $url = $this->urls[0];
        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/wholesale-price?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(
            ['buyerEmail' => self::EMAIL, 'sku' => 'AB 12/X', 'currency' => 'USD', 'quantity' => '1'],
            $query
        );
    }

    public function testTheKeyTravelsInXApiKeyOnlyNeverInAuthorization(): void
    {
        // StorefrontIdentityGuard refuses two storefront credentials, and Authorization is
        // the platform-token header on these routes.
        $this->respond(200, '{"status":"none"}');

        $this->client()->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL]);

        self::assertSame(self::KEY, $this->headers['X-Api-Key'] ?? null);
        foreach (array_keys($this->headers) as $name) {
            self::assertNotSame('authorization', strtolower((string) $name), 'Authorization must not be sent');
        }
    }

    public function testEveryRequestGetsAFreshCurlSoNoEarlierHeaderCanLeakIn(): void
    {
        $this->respond(200, '{"status":"none"}');
        $this->curlFactory->expects($this->exactly(2))->method('create');

        $client = $this->client();
        $client->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL]);
        $client->get(StorefrontClient::ROUTE_ORDER_LIMITS, ['buyerEmail' => self::EMAIL, 'sku' => 'A']);
    }

    public function testAnUnknownRouteIsRefusedWithoutAnyRequest(): void
    {
        $this->curlFactory->expects($this->never())->method('create');

        $result = $this->client()->get('wholesale-signup', ['buyerEmail' => self::EMAIL]);

        self::assertFalse($result['ok']);
    }

    public function testNoKeyMeansNoRequest(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('');
        $this->curlFactory->expects($this->never())->method('create');

        $client = new StorefrontClient($config, $this->curlFactory, new Json(), $this->createMock(LoggerInterface::class));

        self::assertFalse($client->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL])['ok']);
    }

    public function testEmptyQueryValuesAreNotSent(): void
    {
        $this->respond(200, '{"status":"none"}');

        $this->client()->get(StorefrontClient::ROUTE_ORDER_LIMITS, ['buyerEmail' => self::EMAIL, 'sku' => '']);

        self::assertStringNotContainsString('sku=', $this->urls[0]);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function failures(): array
    {
        return [
            'server error' => [500, '{"message":"boom"}'],
            'throttled' => [429, '{"message":"Too Many Requests"}'],
            'unauthorised' => [401, '{"message":"Invalid API key"}'],
            'not found' => [404, ''],
            'html from a proxy' => [200, '<html><body>Bad gateway</body></html>'],
            'empty body' => [200, ''],
            'JSON scalar' => [200, '"priced"'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testAnythingButA2xxJsonObjectFailsClosed(int $status, string $body): void
    {
        $this->respond($status, $body);

        $result = $this->client()->get(
            StorefrontClient::ROUTE_WHOLESALE_PRICE,
            ['buyerEmail' => self::EMAIL, 'sku' => 'A']
        );

        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('data', $result);
        self::assertSame($status, $result['status']);
    }

    public function testATransportFailureFailsClosed(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \Exception('Could not resolve host'));
        $factory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($curl);

        $client = new StorefrontClient($this->config, $factory, new Json(), $this->createMock(LoggerInterface::class));
        $result = $client->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL]);

        self::assertFalse($result['ok']);
        self::assertSame(0, $result['status']);
    }

    public function testLogsNeverCarryTheKeyTheEmailOrTheBody(): void
    {
        $this->respond(500, '{"message":"buyer ' . self::EMAIL . ' not found"}');

        $this->client()->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL]);

        self::assertNotEmpty($this->logLines, 'a failure is logged');
        foreach ($this->logLines as $line) {
            self::assertStringNotContainsString(self::KEY, $line);
            self::assertStringNotContainsString(self::EMAIL, $line);
            self::assertStringNotContainsString('buyer%40', $line);
            self::assertStringNotContainsString('not found', $line);
        }
    }

    public function testASuccessfulReadIsReturnedDecoded(): void
    {
        $this->respond(200, '{"status":"grouped","name":"Tier 2","code":"T2"}');

        $result = $this->client()->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => self::EMAIL]);

        self::assertSame(['ok' => true, 'status' => 200, 'data' => ['status' => 'grouped', 'name' => 'Tier 2', 'code' => 'T2']], $result);
    }

    /**
     * The quote-upload call (lane MG-TARGET-ATTACH): raw bytes, X-Api-Key only, the signed-in
     * customer's email OR the guest token in the query, never both.
     *
     * Mutations (applied, red, restored): send Authorization -> red; post the bytes as an
     * array (url-encoded) -> red; send upload_token beside buyerEmail -> red.
     */
    public function testAQuoteFileIsPostedAsRawBytesWithTheKeyInXApiKeyOnly(): void
    {
        $this->respond(201, '{"uploadId":"3f2b8c1e-9d4a-4b7e-8c21-5a6d7e8f9a0b","size":5}');

        $result = $this->client()->uploadQuoteFile('%PDF-', 'spec sheet.pdf', self::EMAIL, 'ignored-token', 1);

        self::assertTrue($result['ok']);
        self::assertSame(['%PDF-'], $this->posted);
        self::assertSame(self::KEY, $this->headers['X-Api-Key'] ?? null);
        self::assertSame('application/octet-stream', $this->headers['Content-Type'] ?? null);
        foreach (array_keys($this->headers) as $name) {
            self::assertNotSame('authorization', strtolower((string) $name));
        }
        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/quote-upload?', $this->urls[0]);
        parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
        self::assertSame(['name' => 'spec sheet.pdf', 'buyerEmail' => self::EMAIL], $query);
    }

    public function testAGuestsLaterUploadSendsTheTokenAndNoEmail(): void
    {
        $this->respond(201, '{"uploadId":"3f2b8c1e-9d4a-4b7e-8c21-5a6d7e8f9a0b"}');

        $this->client()->uploadQuoteFile('%PDF-', 'a.pdf', '', 'tok', 1);

        parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
        self::assertSame(['name' => 'a.pdf', 'upload_token' => 'tok'], $query);
    }

    public function testARefusedUploadIsNotOkAndLogsTheStatusOnly(): void
    {
        $this->respond(400, '{"statusCode":400,"message":"Files can be at most 5 MB"}');

        $result = $this->client()->uploadQuoteFile('%PDF-', 'secret-name.pdf', self::EMAIL, '', 1);

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['status']);
        self::assertSame('Files can be at most 5 MB', $result['message']);
        foreach ($this->logLines as $line) {
            self::assertStringNotContainsString('secret-name', $line);
            self::assertStringNotContainsString(self::EMAIL, $line);
        }
    }
}
