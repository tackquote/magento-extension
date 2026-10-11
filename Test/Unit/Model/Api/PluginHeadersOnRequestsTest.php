<?php
/**
 * Every request this module makes to TackQuote carries the PluginHeaders, beside the key and
 * never in place of it: Model\Api\Client (Magento plugin routes), Model\Api\StorefrontClient
 * (/storefront/v1 reads, the quote upload, the batched price resolve) and
 * Model\Forms\AccountApiClient (wholesale and net terms).
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
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Api\PluginHeaders;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\AccountApiClient;
use TackQuote\Quotes\Model\QuoteCheckout\LandingUrl;

/**
 * @covers \TackQuote\Quotes\Model\Api\Client
 * @covers \TackQuote\Quotes\Model\Api\StorefrontClient
 * @covers \TackQuote\Quotes\Model\Forms\AccountApiClient
 */
class PluginHeadersOnRequestsTest extends TestCase
{
    private const KEY = 'tq_key';

    private const PLUGIN = [
        'X-TackQuote-Plugin-Version' => '1.4.1',
        'X-TackQuote-Site-Url' => 'https://shop.example.test',
    ];

    /** @var array<int, array<string, string>> Header sets, one per request. */
    private $sent = [];

    /** @var array<int, int|null> The store id each PluginHeaders::get() call was asked for. */
    private $asked = [];

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn(self::KEY);
        $config->method('getApiBaseUrl')->willReturn('https://api.tackquote.test/v1');

        return $config;
    }

    private function pluginHeaders(): PluginHeaders
    {
        $headers = $this->createMock(PluginHeaders::class);
        $headers->method('get')->willReturnCallback(function ($storeId): array {
            $this->asked[] = $storeId;

            return self::PLUGIN;
        });

        return $headers;
    }

    /**
     * A Curl that records setHeaders()/addHeader() per request and answers 200 with $body.
     *
     * @param string $body
     * @return Curl
     */
    private function curl(string $body): Curl
    {
        $curl = $this->createMock(Curl::class);
        $index = count($this->sent);
        $this->sent[$index] = [];
        $curl->method('setHeaders')->willReturnCallback(function ($headers) use ($index): void {
            $this->sent[$index] = $headers;
        });
        $curl->method('addHeader')->willReturnCallback(function ($name, $value) use ($index): void {
            $this->sent[$index][(string) $name] = (string) $value;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn($body);

        return $curl;
    }

    private function factory(string $body): CurlFactory
    {
        $factory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(fn () => $this->curl($body));

        return $factory;
    }

    /**
     * @param array<string, string> $headers
     * @return void
     */
    private function assertPluginHeadersBesideTheKey(array $headers): void
    {
        self::assertSame(self::PLUGIN['X-TackQuote-Plugin-Version'], $headers['X-TackQuote-Plugin-Version'] ?? null);
        self::assertSame(self::PLUGIN['X-TackQuote-Site-Url'], $headers['X-TackQuote-Site-Url'] ?? null);
        self::assertSame(self::KEY, $headers['X-Api-Key'] ?? null, 'X-Api-Key stays the credential');
    }

    public function testTheMagentoPluginRoutesCarryThem(): void
    {
        $curl = $this->curl('{"ok":true}');
        $client = new Client(
            $this->config(),
            $curl,
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->createConfiguredMock(LandingUrl::class, ['headerValue' => 'off']),
            $this->pluginHeaders()
        );

        $client->testConnection(5);

        $this->assertPluginHeadersBesideTheKey($this->sent[0]);
        self::assertSame('Bearer ' . self::KEY, $this->sent[0]['Authorization'] ?? null, 'unchanged on these routes');
        self::assertSame([5], $this->asked, 'the site URL is the store the request is for');
    }

    public function testEveryStorefrontRequestCarriesThemAndStillNoAuthorization(): void
    {
        $client = new StorefrontClient(
            $this->config(),
            $this->factory('{"status":"none"}'),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->pluginHeaders()
        );

        $client->get(StorefrontClient::ROUTE_BUYER_GROUP, ['buyerEmail' => 'b@example.test'], 2);
        $client->uploadQuoteFile('%PDF-', 'a.pdf', 'b@example.test', '', 2);
        $client->resolvePricing([['sku' => 'A', 'quantity' => 1]], 'b@example.test', 2);

        self::assertCount(3, $this->sent);
        foreach ($this->sent as $headers) {
            $this->assertPluginHeadersBesideTheKey($headers);
            foreach (array_keys($headers) as $name) {
                self::assertNotSame('authorization', strtolower((string) $name));
            }
        }
        self::assertSame([2, 2, 2], $this->asked);
    }

    public function testTheIdentityHeadersAndThePluginHeadersBothGoOut(): void
    {
        $client = new StorefrontClient(
            $this->config(),
            $this->factory('{"status":"none"}'),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->pluginHeaders()
        );

        $client->get(
            StorefrontClient::ROUTE_BUYER_GROUP,
            ['buyerEmail' => 'b@example.test'],
            1,
            ['X-TackQuote-Platform' => 'magento', 'X-TackQuote-Store-Id' => '0b9f6c1e-3a52-4d0e-9a0f-5c1d2e3f4a5b']
        );

        $this->assertPluginHeadersBesideTheKey($this->sent[0]);
        self::assertSame('magento', $this->sent[0]['X-TackQuote-Platform'] ?? null);
    }

    public function testWholesaleAndNetTermsRequestsCarryThem(): void
    {
        $client = new AccountApiClient(
            $this->config(),
            $this->factory('{"slug":"default","fields":[]}'),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->pluginHeaders()
        );

        $client->getWholesaleForm('', 4);

        self::assertCount(1, $this->sent);
        $this->assertPluginHeadersBesideTheKey($this->sent[0]);
        self::assertSame([4], $this->asked);
    }
}
