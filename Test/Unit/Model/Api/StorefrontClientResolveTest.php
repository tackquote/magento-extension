<?php
/**
 * `POST /storefront-pricing/resolve`: ONE request for the whole cart, the key in X-Api-Key and
 * nowhere else, the body the server's DTO takes, at most 50 lines, and fail closed.
 *
 * The wire contract is tack apps/api/src/modules/pricing-rules/storefront-pricing.controller.ts
 * and dto/storefront-pricing.dto.ts (items 1..50, {sku, quantity >= 1}, optional buyerEmail).
 * These tests assert on the request that goes out.
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
use TackQuote\Quotes\Model\Api\PluginHeaders;

/**
 * @covers \TackQuote\Quotes\Model\Api\StorefrontClient
 */
class StorefrontClientResolveTest extends TestCase
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

    /** @var array<string, string> */
    private $headers = [];

    /** @var string[] */
    private $urls = [];

    /** @var array<int, mixed> */
    private $posted = [];

    /** @var string[] */
    private $logLines = [];

    /** @var int */
    private $gets = 0;

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
        $this->curl->method('post')->willReturnCallback(function ($url, $body): void {
            $this->urls[] = $url;
            $this->posted[] = $body;
        });
        $this->curl->method('get')->willReturnCallback(function (): void {
            $this->gets++;
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

        return new StorefrontClient(
            $this->config,
            $this->curlFactory,
            new Json(),
            $logger,
            $this->createConfiguredMock(PluginHeaders::class, ['get' => []])
        );
    }

    private function respond(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    /**
     * @return array<int, array{sku: string, quantity: int}>
     */
    private static function lines(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = ['sku' => 'SKU-' . $i, 'quantity' => $i];
        }

        return $out;
    }

    public function testTheWholeCartGoesOutAsOnePostWithTheServersBodyShape(): void
    {
        $this->respond(201, '{"buyerMatched":true,"items":[]}');

        $result = $this->client()->resolvePricing(self::lines(3), ' ' . self::EMAIL . ' ');

        self::assertTrue($result['ok']);
        self::assertSame([self::API_BASE . '/storefront-pricing/resolve'], $this->urls, 'exactly one request');
        self::assertSame(0, $this->gets);
        self::assertSame(
            [
                'buyerEmail' => self::EMAIL,
                'items' => [
                    ['sku' => 'SKU-1', 'quantity' => 1],
                    ['sku' => 'SKU-2', 'quantity' => 2],
                    ['sku' => 'SKU-3', 'quantity' => 3],
                ],
            ],
            json_decode((string) $this->posted[0], true),
            'a JSON body: the DTO takes integer quantities, not form fields'
        );
    }

    public function testTheKeyTravelsInXApiKeyOnly(): void
    {
        $this->respond(201, '{"buyerMatched":false,"items":[]}');

        $this->client()->resolvePricing(self::lines(1), self::EMAIL);

        self::assertSame(self::KEY, $this->headers['X-Api-Key'] ?? null);
        self::assertSame('application/json', $this->headers['Content-Type'] ?? null);
        foreach (array_keys($this->headers) as $name) {
            self::assertNotSame('authorization', strtolower((string) $name), 'no second credential');
        }
    }

    public function testFiftyLinesAreSentAndFiftyOneAreRefusedBeforeAnyRequest(): void
    {
        $this->respond(201, '{"buyerMatched":true,"items":[]}');

        self::assertTrue($this->client()->resolvePricing(self::lines(50), self::EMAIL)['ok']);
        self::assertCount(1, $this->urls);

        $refused = $this->client()->resolvePricing(self::lines(51), self::EMAIL);
        self::assertFalse($refused['ok']);
        self::assertCount(1, $this->urls, 'a 51-line batch is a 400 for the whole cart, so it is never sent');
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function unsendable(): array
    {
        return [
            'no lines' => [[], self::EMAIL],
            'no email' => [[['sku' => 'A', 'quantity' => 1]], '  '],
            'zero quantity' => [[['sku' => 'A', 'quantity' => 0]], self::EMAIL],
            'string quantity' => [[['sku' => 'A', 'quantity' => '2']], self::EMAIL],
            'empty sku' => [[['sku' => '', 'quantity' => 1]], self::EMAIL],
        ];
    }

    /**
     * @dataProvider unsendable
     */
    public function testAnUnsendableBatchMakesNoRequest(array $lines, string $email): void
    {
        $result = $this->client()->resolvePricing($lines, $email);

        self::assertFalse($result['ok']);
        self::assertSame([], $this->urls);
    }

    public function testNoKeyMeansNoRequest(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('');
        $client = new StorefrontClient(
            $config,
            $this->curlFactory,
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->createConfiguredMock(PluginHeaders::class, ['get' => []])
        );

        self::assertFalse($client->resolvePricing(self::lines(1), self::EMAIL)['ok']);
        self::assertSame([], $this->urls);
    }

    public function testAnErrorStatusIsNotOkAndCarriesTheStatus(): void
    {
        $this->respond(429, '{"statusCode":429}');

        $result = $this->client()->resolvePricing(self::lines(1), self::EMAIL);

        self::assertFalse($result['ok']);
        self::assertSame(429, $result['status']);
    }

    public function testABodyThatIsNotJsonIsNotOk(): void
    {
        $this->respond(201, '<html>upgrade</html>');

        self::assertFalse($this->client()->resolvePricing(self::lines(1), self::EMAIL)['ok']);
    }

    public function testTheLogNeverCarriesTheKeyTheEmailOrASku(): void
    {
        $this->respond(500, 'oops');

        $this->client()->resolvePricing(self::lines(2), self::EMAIL);

        self::assertNotEmpty($this->logLines);
        foreach ($this->logLines as $line) {
            self::assertStringNotContainsString(self::KEY, $line);
            self::assertStringNotContainsString(self::EMAIL, $line);
            self::assertStringNotContainsString('SKU-1', $line);
        }
    }
}
