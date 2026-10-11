<?php
/**
 * "Connect with TackQuote", the orchestration: the request payload, the consent
 * redirect, every refusal of the return (state, expiry, denial, missing code),
 * the exchange, encrypted storage and the connection test, and Disconnect.
 *
 * Mutants run (listed in the PR): skip pending->take, store the key before the
 * shape check, send the verifier at start, revoke a pasted key, report success
 * when the exchange failed.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Connect\ConnectApi;
use TackQuote\Quotes\Model\Connect\ConnectService;
use TackQuote\Quotes\Model\Connect\KeyStore;
use TackQuote\Quotes\Model\Connect\PendingConnect;
use TackQuote\Quotes\Model\Connect\Pkce;

/**
 * @covers \TackQuote\Quotes\Model\Connect\ConnectService
 */
class ConnectServiceTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const STATE = 'c3RhdGUtc3RhdGUtc3RhdGUtc3RhdGUtc3RhdGUtc3Q';
    private const CODE = 'Y29kZS1jb2RlLWNvZGUtY29kZS1jb2RlLWNvZGUtY29';
    private const REQ = 'mgr_AAAAAAAAAAAAAAAAAAAAAA';
    private const KEY = 'tack_sk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SITE = 'https://shop.example.com/';

    /** @var Config&MockObject */
    private $config;

    /** @var ConnectApi&MockObject */
    private $api;

    /** @var PendingConnect&MockObject */
    private $pending;

    /** @var KeyStore&MockObject */
    private $keys;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiBaseUrl')->willReturn('https://api.tackquote.com/v1');
        $this->api = $this->createMock(ConnectApi::class);
        $this->pending = $this->createMock(PendingConnect::class);
        $this->keys = $this->createMock(KeyStore::class);
    }

    private function service(): ConnectService
    {
        $pkce = $this->createMock(Pkce::class);
        $pkce->method('newVerifier')->willReturn(self::VERIFIER);
        $pkce->method('newState')->willReturn(self::STATE);

        return new ConnectService(
            $this->config,
            $this->api,
            $this->pending,
            $this->keys,
            $pkce,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function store(array $over = []): array
    {
        return array_merge([
            'siteUrl' => self::SITE,
            'adminUrl' => 'https://shop.example.com/admin/tackquote/dashboard/index/',
            'redirectUri' => 'https://shop.example.com/admin/tackquote/connect/callback/key/abc/',
            'siteName' => 'Example Shop',
            'pluginVersion' => '1.5.0',
            'platformVersion' => '2.4.7',
            'locale' => 'en_US',
        ], $over);
    }

    private static function ok(array $data = []): array
    {
        return ['ok' => true, 'status' => 200, 'data' => $data, 'message' => ''];
    }

    private static function refused(int $status, string $message = ''): array
    {
        return ['ok' => false, 'status' => $status, 'data' => [], 'message' => $message];
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartSendsTheChallengeNotTheVerifierAndRedirectsToTheConsentPage(): void
    {
        $connectUrl = 'https://app.tackquote.com/auth/connect/magento?request=' . self::REQ;
        $this->api->expects($this->once())->method('createRequest')
            ->with($this->callback(function (array $body): bool {
                $this->assertSame([
                    'siteUrl' => self::SITE,
                    'adminUrl' => 'https://shop.example.com/admin/tackquote/dashboard/index/',
                    'redirectUri' => 'https://shop.example.com/admin/tackquote/connect/callback/key/abc/',
                    'state' => self::STATE,
                    'codeChallenge' => Pkce::challenge(self::VERIFIER),
                    'codeChallengeMethod' => 'S256',
                    'siteName' => 'Example Shop',
                    'pluginVersion' => '1.5.0',
                    'platformVersion' => '2.4.7',
                    'locale' => 'en_US',
                ], $body);
                $this->assertStringNotContainsString(self::VERIFIER, json_encode($body));
                return true;
            }))
            ->willReturn(self::ok(['requestId' => self::REQ, 'connectUrl' => $connectUrl, 'expiresIn' => 900]));
        $this->pending->expects($this->once())->method('begin')
            ->with(7, self::VERIFIER, self::STATE, self::REQ, 1000);

        $this->assertSame(['redirect' => $connectUrl], $this->service()->start(7, $this->store(), 1000));
    }

    public function testStartRefusesAPublicStoreOnPlainHttpWithoutCallingTackQuote(): void
    {
        $this->api->expects($this->never())->method('createRequest');
        $this->pending->expects($this->never())->method('begin');
        $out = $this->service()->start(7, $this->store(['siteUrl' => 'http://shop.example.com/']), 1000);
        $this->assertSame('error', $out['type']);
        $this->assertStringContainsString('https', $out['message']);
    }

    public function testStartAllowsPlainHttpOnADevelopmentHost(): void
    {
        $this->api->method('createRequest')->willReturn(self::ok([
            'requestId' => self::REQ,
            'connectUrl' => 'https://app.tackquote.com/auth/connect/magento?request=' . self::REQ,
        ]));
        $out = $this->service()->start(7, $this->store(['siteUrl' => 'http://magento.test/']), 1000);
        $this->assertArrayHasKey('redirect', $out);
    }

    public function testStartWillNotOpenAConsentUrlOffTheTackQuoteApp(): void
    {
        $this->api->method('createRequest')->willReturn(self::ok([
            'requestId' => self::REQ,
            'connectUrl' => 'https://evil.example.net/auth/connect/magento?request=' . self::REQ,
        ]));
        $this->pending->expects($this->never())->method('begin');
        $out = $this->service()->start(7, $this->store(), 1000);
        $this->assertSame('error', $out['type']);
    }

    public function testStartNamesAPlanRefusal(): void
    {
        $this->api->method('createRequest')->willReturn(self::refused(403, 'Plan limit'));
        $out = $this->service()->start(7, $this->store(), 1000);
        $this->assertSame('error', $out['type']);
        $this->assertStringContainsString('plan does not allow it', $out['message']);
    }

    // ── finish ────────────────────────────────────────────────────────────────

    public function testFinishRefusesAStateThatDoesNotMatchAndExchangesNothing(): void
    {
        $this->pending->expects($this->once())->method('take')->with(7, 'forged', 1100)->willReturn(null);
        $this->api->expects($this->never())->method('exchange');
        $this->keys->expects($this->never())->method('saveConnectedKey');
        $out = $this->service()->finish(7, ['code' => self::CODE, 'state' => 'forged'], self::SITE, 1100);
        $this->assertSame('error', $out['type']);
        $this->assertStringContainsString('could not be verified', $out['message']);
    }

    public function testFinishRefusesAMissingStateAndCode(): void
    {
        $this->pending->method('take')->willReturn(null);
        $this->api->expects($this->never())->method('exchange');
        $out = $this->service()->finish(7, [], self::SITE, 1100);
        $this->assertSame('error', $out['type']);
    }

    public function testFinishRefusesAMissingCodeAfterAValidState(): void
    {
        $this->pending->method('take')->willReturn(self::VERIFIER);
        $this->api->expects($this->never())->method('exchange');
        $out = $this->service()->finish(7, ['state' => self::STATE], self::SITE, 1100);
        $this->assertSame('error', $out['type']);
        $this->assertStringContainsString('no usable code', $out['message']);
    }

    public function testFinishReportsACancelAsNothingChanged(): void
    {
        $this->pending->method('take')->willReturn(self::VERIFIER);
        $this->api->expects($this->never())->method('exchange');
        $out = $this->service()->finish(7, ['error' => 'access_denied', 'state' => self::STATE], self::SITE, 1100);
        $this->assertSame(['type' => 'warning', 'message' => 'TackQuote was not connected. Nothing changed.'], $out);
    }

    public function testFinishExchangesStoresEncryptedThroughKeyStoreAndTests(): void
    {
        $this->pending->method('take')->with(7, self::STATE, 1100)->willReturn(self::VERIFIER);
        $this->api->expects($this->once())->method('exchange')
            ->with(['code' => self::CODE, 'codeVerifier' => self::VERIFIER, 'siteUrl' => self::SITE])
            ->willReturn(self::ok(['apiKey' => self::KEY, 'scopes' => ['quotes:write'], 'tenantName' => 'Acme']));
        $this->keys->expects($this->once())->method('saveConnectedKey')->with(self::KEY, 1100);
        $this->api->expects($this->once())->method('ping')->with(self::KEY)
            ->willReturn(self::ok(['ok' => true, 'connectedVia' => 'connect']));
        $out = $this->service()->finish(7, ['code' => self::CODE, 'state' => self::STATE], self::SITE, 1100);
        $this->assertSame('success', $out['type']);
        $this->assertStringContainsString('Acme', $out['message']);
    }

    public function testFinishSavesNothingWhenTheExchangeIsRefused(): void
    {
        $this->pending->method('take')->willReturn(self::VERIFIER);
        $this->api->method('exchange')->willReturn(self::refused(400, 'This connect code is invalid'));
        $this->keys->expects($this->never())->method('saveConnectedKey');
        $out = $this->service()->finish(7, ['code' => self::CODE, 'state' => self::STATE], self::SITE, 1100);
        $this->assertSame('error', $out['type']);
    }

    public function testFinishSavesNothingWhenTheAnswerCarriesNoUsableKey(): void
    {
        $this->pending->method('take')->willReturn(self::VERIFIER);
        $this->api->method('exchange')->willReturn(self::ok(['apiKey' => '<script>']));
        $this->keys->expects($this->never())->method('saveConnectedKey');
        $out = $this->service()->finish(7, ['code' => self::CODE, 'state' => self::STATE], self::SITE, 1100);
        $this->assertSame('error', $out['type']);
    }

    public function testFinishWarnsWhenTheSavedKeyFailsItsTest(): void
    {
        $this->pending->method('take')->willReturn(self::VERIFIER);
        $this->api->method('exchange')->willReturn(self::ok(['apiKey' => self::KEY]));
        $this->api->method('ping')->willReturn(self::refused(401, 'Invalid API key'));
        $out = $this->service()->finish(7, ['code' => self::CODE, 'state' => self::STATE], self::SITE, 1100);
        $this->assertSame('warning', $out['type']);
        $this->assertStringContainsString('Invalid API key', $out['message']);
    }

    // ── disconnect ────────────────────────────────────────────────────────────

    public function testDisconnectRevokesAConnectKeyThenClearsIt(): void
    {
        $this->config->method('getDefaultApiKey')->willReturn(self::KEY);
        $this->config->method('getConnectedVia')->willReturn(Config::VIA_CONNECT);
        $this->api->expects($this->once())->method('revoke')->with(self::KEY)
            ->willReturn(['ok' => true, 'status' => 204, 'data' => [], 'message' => '']);
        $this->keys->expects($this->once())->method('clear');
        $this->assertSame('success', $this->service()->disconnect()['type']);
    }

    public function testDisconnectNeverRevokesAPastedKey(): void
    {
        $this->config->method('getDefaultApiKey')->willReturn(self::KEY);
        $this->config->method('getConnectedVia')->willReturn(Config::VIA_KEY);
        $this->api->expects($this->never())->method('revoke');
        $this->keys->expects($this->once())->method('clear');
        $out = $this->service()->disconnect();
        $this->assertSame('success', $out['type']);
        $this->assertStringContainsString('keeps working in TackQuote', $out['message']);
    }

    public function testDisconnectClearsLocallyAndWarnsWhenTheRevokeIsNotConfirmed(): void
    {
        $this->config->method('getDefaultApiKey')->willReturn(self::KEY);
        $this->config->method('getConnectedVia')->willReturn(Config::VIA_CONNECT);
        $this->api->method('revoke')->willReturn(self::refused(0));
        $this->keys->expects($this->once())->method('clear');
        $this->assertSame('warning', $this->service()->disconnect()['type']);
    }

    public function testDisconnectWithNoKeyChangesNothing(): void
    {
        $this->config->method('getDefaultApiKey')->willReturn('');
        $this->api->expects($this->never())->method('revoke');
        $this->keys->expects($this->never())->method('clear');
        $this->assertSame('warning', $this->service()->disconnect()['type']);
    }
}
