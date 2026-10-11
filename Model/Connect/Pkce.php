<?php
/**
 * PKCE and random-value helpers for "Connect with TackQuote".
 *
 * RFC 7636: the code verifier is 43 to 128 characters of the unreserved set
 * (section 4.1); the S256 challenge is BASE64URL(SHA256(ASCII(verifier))),
 * unpadded (section 4.2 and appendix A). The verifier is 32 random bytes,
 * base64url, exactly 43 characters: the length TackQuote's exchange accepts
 * (tack `storefront-connect.store.ts`, `pkceVerifierMatches`) and the one the
 * WooCommerce plugin 1.11.0 generates (`Tack_Connect::new_verifier()`).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

class Pkce
{
    /** The exact shape of a verifier, a code, and the state this module sends. */
    public const B64URL_43 = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * Unpadded base64url.
     *
     * @param string $bytes
     * @return string
     */
    public function b64url(string $bytes): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- RFC 7636 base64url, not obfuscation.
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * A new code verifier: 32 random bytes, 43 characters.
     *
     * @return string
     */
    public function newVerifier(): string
    {
        return $this->b64url(random_bytes(32));
    }

    /**
     * A new opaque `state`: 32 random bytes, 43 characters. Only its SHA-256 is
     * kept on this side (PendingConnect), so a leaked flag row cannot answer a
     * return.
     *
     * @return string
     */
    public function newState(): string
    {
        return $this->b64url(random_bytes(32));
    }

    /**
     * The S256 challenge of a verifier.
     *
     * @param string $verifier
     * @return string
     */
    public function challenge(string $verifier): string
    {
        return $this->b64url(hash('sha256', $verifier, true));
    }
}
