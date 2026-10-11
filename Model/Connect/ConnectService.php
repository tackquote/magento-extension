<?php
/**
 * "Connect with TackQuote": connect this store by signing in to TackQuote and
 * approving, instead of copying an API key. The Magento twin of the WooCommerce
 * plugin's `Tack_Connect` (1.11.0); the TackQuote half is tack
 * `apps/api/src/modules/integrations/magento/connect/` on the shared
 * `storefront-connect` flow.
 *
 *   1. start    (admin POST, form key + ACL, Controller\Adminhtml\Connect\Start)
 *               The store must be https (http only on a development host).
 *               A PKCE verifier and a random `state` are made; TackQuote is
 *               asked, server to server, to open a connect request naming this
 *               store, its Admin return route and the verifier's S256
 *               challenge. The verifier (encrypted) and the state's hash are
 *               kept server-side for 15 minutes (PendingConnect); the browser is
 *               sent to the consent URL TackQuote answered with, after
 *               ConnectUrlPolicy::consentHost() accepted it.
 *   2. consent  On TackQuote the admin signs in (or creates an account) and
 *               approves or cancels. TackQuote sends the browser back to
 *               `tackquote/connect/callback` with a one-time `code` (or
 *               `error=access_denied`) and the `state` unchanged.
 *   3. finish   (admin GET, secret key + ACL, Controller\Adminhtml\Connect\Callback)
 *               The pending connect of THIS admin user is taken once and the
 *               state compared; only then is the code exchanged, server to
 *               server, with the verifier for an API key, which is stored
 *               encrypted exactly where a pasted key is, and tested.
 *
 * Disconnect revokes the key in TackQuote when Connect issued it, then removes
 * it from this store. A pasted key keeps working exactly as before; this only
 * adds a second way in. Never reports a success it did not get.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class ConnectService
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ConnectApi
     */
    private $api;

    /**
     * @var PendingConnect
     */
    private $pending;

    /**
     * @var KeyStore
     */
    private $keys;

    /**
     * @var Pkce
     */
    private $pkce;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param ConnectApi $api
     * @param PendingConnect $pending
     * @param KeyStore $keys
     * @param Pkce $pkce
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        ConnectApi $api,
        PendingConnect $pending,
        KeyStore $keys,
        Pkce $pkce,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->api = $api;
        $this->pending = $pending;
        $this->keys = $keys;
        $this->pkce = $pkce;
        $this->logger = $logger;
    }

    // ── 1. Start ──────────────────────────────────────────────────────────────

    /**
     * Open a connect request with TackQuote.
     *
     * @param int $userId The admin user pressing the button.
     * @param array $store {siteUrl, adminUrl, redirectUri, siteName, pluginVersion, platformVersion, locale}
     * @param int|null $now
     * @return array{redirect: string}|array{type: string, message: string}
     */
    public function start(int $userId, array $store, ?int $now = null): array
    {
        $now = $now ?? time();
        $siteUrl = (string) ($store['siteUrl'] ?? '');
        if (!ConnectUrlPolicy::isAllowedUrl($siteUrl)) {
            return $this->notice(
                'error',
                (string) __('Connect needs your store to use https. Paste an API key instead, or switch your store to https.')
            );
        }

        $verifier = $this->pkce->newVerifier();
        $state = $this->pkce->newState();
        $body = array_filter([
            'siteUrl' => $siteUrl,
            'adminUrl' => (string) ($store['adminUrl'] ?? ''),
            'redirectUri' => (string) ($store['redirectUri'] ?? ''),
            'state' => $state,
            'codeChallenge' => Pkce::challenge($verifier),
            'codeChallengeMethod' => 'S256',
            'siteName' => $this->clip((string) ($store['siteName'] ?? ''), 120),
            'pluginVersion' => $this->clip((string) ($store['pluginVersion'] ?? ''), 32),
            'platformVersion' => $this->clip((string) ($store['platformVersion'] ?? ''), 32),
            'locale' => $this->clip((string) ($store['locale'] ?? ''), 20),
        ], static function ($value): bool {
            return $value !== '';
        });

        $answer = $this->api->createRequest($body);
        if (!$answer['ok']) {
            return $this->notice('error', $this->failureMessage($answer, 'start'));
        }
        $requestId = is_string($answer['data']['requestId'] ?? null) ? $answer['data']['requestId'] : '';
        $connectUrl = is_string($answer['data']['connectUrl'] ?? null) ? $answer['data']['connectUrl'] : '';
        if (ConnectUrlPolicy::consentHost($connectUrl, $requestId, $this->config->getApiBaseUrl()) === '') {
            return $this->notice(
                'error',
                (string) __('TackQuote answered with a sign-in address this module will not open, so nothing was changed. Check the API base URL, or paste an API key instead.')
            );
        }

        $this->pending->begin($userId, $verifier, $state, $requestId, $now);

        return ['redirect' => $connectUrl];
    }

    // ── 3. Finish ─────────────────────────────────────────────────────────────

    /**
     * Verify the return and exchange the code.
     *
     * @param int $userId The admin user the callback runs as.
     * @param array $query The callback's query (only code, state and error are read).
     * @param string $siteUrl This store's base URL, as sent at start.
     * @param int|null $now
     * @return array{type: string, message: string}
     */
    public function finish(int $userId, array $query, string $siteUrl, ?int $now = null): array
    {
        $now = $now ?? time();
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $error = is_string($query['error'] ?? null) ? $query['error'] : '';
        $restart = (string) __('Press Connect with TackQuote again.');

        // Taken once, before anything else can use it: a replayed return finds nothing.
        $verifier = $this->pending->take($userId, $state, $now);
        if ($verifier === null) {
            return $this->notice(
                'error',
                (string) __('TackQuote was not connected: this sign-in could not be verified, expired, or was already used. Nothing was changed.') . ' ' . $restart
            );
        }

        if ($error !== '') {
            if ($error === 'access_denied') {
                return $this->notice('warning', (string) __('TackQuote was not connected. Nothing changed.'));
            }
            return $this->notice(
                'error',
                (string) __('TackQuote was not connected: the sign-in did not finish. Nothing was changed.') . ' ' . $restart
            );
        }
        if (!preg_match(Pkce::B64URL_43, $code)) {
            return $this->notice(
                'error',
                (string) __('TackQuote was not connected: the sign-in response carried no usable code. Nothing was changed.') . ' ' . $restart
            );
        }

        $answer = $this->api->exchange([
            'code' => $code,
            'codeVerifier' => $verifier,
            'siteUrl' => $siteUrl,
        ]);
        if (!$answer['ok']) {
            return $this->notice('error', $this->failureMessage($answer, 'exchange'));
        }
        $apiKey = is_string($answer['data']['apiKey'] ?? null) ? $answer['data']['apiKey'] : '';
        if (!self::isUsableKey($apiKey)) {
            return $this->notice(
                'error',
                (string) __('TackQuote answered without a usable API key, so nothing was changed. Press Connect with TackQuote again, or paste an API key.')
            );
        }

        $this->keys->saveConnectedKey($apiKey, $now);

        $workspace = is_string($answer['data']['tenantName'] ?? null)
            ? $this->clip(strip_tags($answer['data']['tenantName']), 120)
            : '';
        $test = $this->api->ping($apiKey);
        if ($test['ok']) {
            return $this->notice(
                'success',
                $workspace !== ''
                    ? (string) __('Connected to TackQuote (workspace: %1). The connection test passed.', $workspace)
                    : (string) __('Connected to TackQuote. The connection test passed.')
            );
        }

        return $this->notice(
            'warning',
            (string) __('TackQuote sent an API key and it was saved, but the connection test did not pass.')
            . ($test['message'] !== '' ? ' ' . $this->clip($test['message'], 300) : '')
        );
    }

    // ── Disconnect ────────────────────────────────────────────────────────────

    /**
     * Revoke the saved default-scope key in TackQuote when Connect issued it,
     * then remove it from this store whatever TackQuote answered.
     *
     * @return array{type: string, message: string}
     */
    public function disconnect(): array
    {
        $key = $this->config->getDefaultApiKey();
        if ($key === '') {
            return $this->notice('warning', (string) __('No API key is saved, so there was nothing to disconnect.'));
        }
        $viaConnect = $this->config->getConnectedVia() === Config::VIA_CONNECT;
        $revoked = $viaConnect ? $this->api->revoke($key)['ok'] : false;
        $this->keys->clear();

        if (!$viaConnect) {
            return $this->notice(
                'success',
                (string) __('Disconnected. The pasted API key was removed from this store. It keeps working in TackQuote until you revoke it there under Settings > Developer > API Keys.')
            );
        }
        if ($revoked) {
            return $this->notice(
                'success',
                (string) __('Disconnected. The API key was removed from this store and revoked in TackQuote.')
            );
        }
        $this->logger->warning('TackQuote Connect: the key was removed locally but TackQuote did not confirm the revoke.');

        return $this->notice(
            'warning',
            (string) __('Disconnected on this store, but TackQuote did not confirm the key was revoked. Revoke it in TackQuote under Settings > Developer > API Keys.')
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A key shape worth storing: printable key characters of a plausible length.
     * The connection test then proves it works.
     *
     * @param string $key
     * @return bool
     */
    public static function isUsableKey(string $key): bool
    {
        return strlen($key) >= 20 && strlen($key) <= 200 && preg_match('/^[A-Za-z0-9._\-]+$/', $key) === 1;
    }

    /**
     * The message for a failed call to TackQuote: what happened and what to do,
     * plus TackQuote's own sentence when it sent one.
     *
     * @param array $answer
     * @param string $step start | exchange
     * @return string
     */
    private function failureMessage(array $answer, string $step): string
    {
        $status = (int) ($answer['status'] ?? 0);
        $said = $status !== 0 ? $this->clip(strip_tags((string) ($answer['message'] ?? '')), 300) : '';

        if ($status === 0) {
            $message = (string) __('Could not reach TackQuote, so nothing was changed. Check the API base URL, then try again in a few minutes.');
        } elseif ($status === 404) {
            $message = (string) __('This TackQuote server does not support Connect with TackQuote for Magento. Paste an API key instead.');
            $said = '';
        } elseif ($status === 429 || $status >= 500) {
            $message = (string) __('TackQuote is busy or unavailable right now, so nothing was changed. Try again in a few minutes.');
        } elseif ($status === 403) {
            $message = (string) __('TackQuote did not connect this store: your TackQuote plan does not allow it. Nothing was changed on this store.');
        } elseif ($step === 'start') {
            $message = (string) __('TackQuote refused to start connecting this store, so nothing was changed.');
        } else {
            $message = (string) __('TackQuote refused the sign-in code: it is invalid, expired or already used. Nothing was changed.');
        }

        return $said !== '' && $said !== $message ? $message . ' ' . $said : $message;
    }

    /**
     * @param string $type success | warning | error
     * @param string $message
     * @return array{type: string, message: string}
     */
    private function notice(string $type, string $message): array
    {
        return ['type' => $type, 'message' => $message];
    }

    /**
     * Trim text to at most $max characters.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    private function clip(string $text, int $max): string
    {
        $text = trim($text);

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
