<?php
/**
 * The one pending "Connect with TackQuote" of an admin user, kept server-side.
 *
 * Between pressing the button and coming back from TackQuote the module must
 * remember the PKCE verifier (it proves, at the exchange, that this store asked)
 * and which `state` it sent. Neither may travel through the browser. They are
 * kept in Magento's flag table (`Magento\Framework\FlagManager`, a database
 * row), keyed by the admin user, for 15 minutes (TackQuote keeps its side of
 * the request for the same time):
 *
 *   verifier   encrypted with the installation's crypt key
 *              (`EncryptorInterface::encrypt`), never stored in clear;
 *   state      its SHA-256 only, compared in constant time;
 *   requestId  TackQuote's request id, for the log;
 *   iat        when it was started.
 *
 * One row per admin user: pressing the button again replaces the previous
 * pending connect (its return is then refused), which also bounds the table.
 * `take()` reads AND deletes, before any check, so a replayed return finds
 * nothing (single use, fail closed).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;

class PendingConnect
{
    /** Seconds a started connect stays usable. */
    public const TTL = 900;

    private const FLAG_PREFIX = 'tackquote_connect_pending_u';

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param FlagManager $flags
     * @param EncryptorInterface $encryptor
     */
    public function __construct(FlagManager $flags, EncryptorInterface $encryptor)
    {
        $this->flags = $flags;
        $this->encryptor = $encryptor;
    }

    /**
     * Remember a started connect for this admin user, replacing any earlier one.
     *
     * @param int $userId
     * @param string $verifier
     * @param string $state
     * @param string $requestId
     * @param int $now
     * @return void
     */
    public function begin(int $userId, string $verifier, string $state, string $requestId, int $now): void
    {
        $this->flags->saveFlag($this->code($userId), [
            'v' => 1,
            'verifier' => $this->encryptor->encrypt($verifier),
            'state' => hash('sha256', $state),
            'request_id' => $requestId,
            'iat' => $now,
        ]);
    }

    /**
     * Take (read and delete) this admin user's pending connect and check it
     * against the returned state. Returns the decrypted verifier, or null when
     * there is none, it expired, or the state does not match.
     *
     * @param int $userId
     * @param string $state
     * @param int $now
     * @return string|null
     */
    public function take(int $userId, string $state, int $now): ?string
    {
        $code = $this->code($userId);
        $pending = $this->flags->getFlagData($code);
        // Deleted BEFORE any check: from here on this pending connect is spent.
        $this->flags->deleteFlag($code);

        if (!is_array($pending) || ($pending['v'] ?? 0) !== 1) {
            return null;
        }
        $iat = (int) ($pending['iat'] ?? 0);
        if ($now - $iat > self::TTL || $iat > $now + 60) {
            return null;
        }
        if ($state === '' || !hash_equals((string) ($pending['state'] ?? ''), hash('sha256', $state))) {
            return null;
        }
        $verifier = trim((string) $this->encryptor->decrypt((string) ($pending['verifier'] ?? '')));

        return preg_match(Pkce::B64URL_43, $verifier) ? $verifier : null;
    }

    /**
     * The flag code of an admin user's pending connect.
     *
     * @param int $userId
     * @return string
     */
    private function code(int $userId): string
    {
        return self::FLAG_PREFIX . $userId;
    }
}
