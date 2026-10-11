<?php
/**
 * The account-route client: what goes out (route, query, headers, body) and fail-closed on
 * what comes back. The wire contract is tack's StorefrontV1Controller and the API-key
 * resolver, which reads `X-TackQuote-Platform` / `X-TackQuote-Store-Id` beside
 * `buyerEmail` + `buyerExternalId`, and refuses `Authorization` beside `X-Api-Key`.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\AccountApiClient;
use TackQuote\Quotes\Model\Forms\AccountIdentity;

/**
 * @covers \TackQuote\Quotes\Model\Forms\AccountApiClient
 * @covers \TackQuote\Quotes\Model\Forms\AccountIdentity
 */
class AccountApiClientTest extends TestCase
{
    private const API_BASE = 'https://api.tackquote.test/v1';
    private const KEY = 'tq_live_secret_key';
    private const INSTALL = '0f0e0d0c-0b0a-4908-8706-050403020100';

    /** @var array<int, array{method: string, url: string, headers: array, body: mixed}> */
    private $sent = [];

    /** @var int */
    private $status = 200;

    /** @var string */
    private $body = '{"status":"ok"}';

    private function client(): AccountApiClient
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn(self::KEY);
        $config->method('getApiBaseUrl')->willReturn(self::API_BASE);

        $factory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () {
            $curl = $this->createMock(Curl::class);
            $headers = [];
            $curl->method('setHeaders')->willReturnCallback(function ($h) use (&$headers): void {
                $headers = $h;
            });
            $curl->method('get')->willReturnCallback(function ($url) use (&$headers): void {
                $this->sent[] = ['method' => 'GET', 'url' => $url, 'headers' => $headers, 'body' => null];
            });
            $curl->method('post')->willReturnCallback(function ($url, $body) use (&$headers): void {
                $this->sent[] = ['method' => 'POST', 'url' => $url, 'headers' => $headers, 'body' => $body];
            });
            $curl->method('getStatus')->willReturnCallback(fn () => $this->status);
            $curl->method('getBody')->willReturnCallback(fn () => $this->body);

            return $curl;
        });

        return new AccountApiClient($config, $factory, new Json(), $this->createMock(LoggerInterface::class));
    }

    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testANamespacingServerGetsTheCustomerIdTheInstallAndThePlatform(): void
    {
        $who = new AccountIdentity(42, ' Buyer@Example.test ', self::INSTALL, true);
        $this->client()->getNetTerms($who);

        $sent = $this->sent[0];
        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/net-terms?', $sent['url']);
        self::assertSame(['buyerEmail' => 'buyer@example.test', 'buyerExternalId' => '42'], $this->query($sent['url']));
        self::assertSame('magento', $sent['headers']['X-TackQuote-Platform'] ?? null);
        self::assertSame(self::INSTALL, $sent['headers']['X-TackQuote-Store-Id'] ?? null);
        self::assertSame(self::KEY, $sent['headers']['X-Api-Key'] ?? null);
        foreach (array_keys($sent['headers']) as $name) {
            self::assertNotSame('authorization', strtolower((string) $name));
        }
    }

    public function testAnOlderServerGetsTheEmailOnlyNeverABareCustomerId(): void
    {
        $who = new AccountIdentity(42, 'buyer@example.test', self::INSTALL, false);
        $this->client()->getNetTerms($who);

        self::assertSame(['buyerEmail' => 'buyer@example.test'], $this->query($this->sent[0]['url']));
        self::assertArrayNotHasKey('X-TackQuote-Platform', $this->sent[0]['headers']);
        self::assertArrayNotHasKey('X-TackQuote-Store-Id', $this->sent[0]['headers']);
    }

    public function testNoInstallIdKeepsThePlatformButSendsNoCustomerId(): void
    {
        // The platform header still goes, so the server never files this shopper under a
        // WooCommerce store of the same workspace.
        $who = new AccountIdentity(42, 'buyer@example.test', '', true);
        $this->client()->submitCreditApplication(['legalBusinessName' => 'Acme'], $who);

        self::assertSame(['buyerEmail' => 'buyer@example.test'], $this->query($this->sent[0]['url']));
        self::assertSame('magento', $this->sent[0]['headers']['X-TackQuote-Platform'] ?? null);
        self::assertArrayNotHasKey('X-TackQuote-Store-Id', $this->sent[0]['headers']);
    }

    public function testTheWholesaleSubmissionAndUploadRoutesAndBodies(): void
    {
        $who = new AccountIdentity(42, 'buyer@example.test', self::INSTALL, true);
        $client = $this->client();
        $client->uploadWholesaleFile('%PDF-1.7', 'cert.pdf', 'trade', 'cert', $who);
        $client->submitWholesale('trade', ['cert' => ['uploadId' => 'u-1']], $who);
        $client->submitWholesale('', [], $who);

        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/wholesale-upload?', $this->sent[0]['url']);
        self::assertSame(
            ['form' => 'trade', 'field' => 'cert', 'name' => 'cert.pdf', 'buyerEmail' => 'buyer@example.test', 'buyerExternalId' => '42'],
            $this->query($this->sent[0]['url'])
        );
        self::assertSame('application/octet-stream', $this->sent[0]['headers']['Content-Type']);
        self::assertSame('%PDF-1.7', $this->sent[0]['body']);

        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/wholesale-signup/trade?', $this->sent[1]['url']);
        self::assertSame('{"values":{"cert":{"uploadId":"u-1"}}}', $this->sent[1]['body']);
        // The default form, and an empty answer set still an OBJECT.
        self::assertStringStartsWith(self::API_BASE . '/storefront/v1/wholesale-signup?', $this->sent[2]['url']);
        self::assertSame('{"values":{}}', $this->sent[2]['body']);
    }

    public function testNobodySignedInSendsNothing(): void
    {
        $who = new AccountIdentity(0, '', self::INSTALL, true);
        $client = $this->client();
        self::assertFalse($client->getNetTerms($who)['ok']);
        self::assertFalse($client->submitWholesale('', [], $who)['ok']);
        self::assertSame([], $this->sent);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function failures(): array
    {
        return [
            '500' => [500, '{"message":"boom"}'],
            'not JSON' => [200, '<html>maintenance</html>'],
            'empty' => [200, ''],
            '404 older server' => [404, '{"statusCode":404,"message":"Cannot GET"}'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testAnythingButA2xxJsonObjectIsNotOk(int $status, string $body): void
    {
        $this->status = $status;
        $this->body = $body;
        $result = $this->client()->getWholesaleApplication(new AccountIdentity(42, 'b@example.test', self::INSTALL, true));

        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('data', $result);
    }
}
