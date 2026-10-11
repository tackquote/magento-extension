<?php
/**
 * PKCE pair generation for "Connect with TackQuote" (RFC 7636 sections 4.1, 4.2,
 * appendix B), against the shape TackQuote's exchange accepts.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Connect\Pkce;

/**
 * @covers \TackQuote\Quotes\Model\Connect\Pkce
 */
class PkceTest extends TestCase
{
    public function testRfc7636AppendixBVector(): void
    {
        // RFC 7636 appendix B: the verifier and its S256 challenge.
        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')
        );
    }

    public function testVerifierAndStateAre43Base64UrlCharactersAndDistinct(): void
    {
        $pkce = new Pkce();
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            foreach ([$pkce->newVerifier(), $pkce->newState()] as $value) {
                $this->assertMatchesRegularExpression(Pkce::B64URL_43, $value);
                $this->assertArrayNotHasKey($value, $seen);
                $seen[$value] = true;
            }
        }
    }

    public function testChallengeIsUnpaddedBase64Url(): void
    {
        $challenge = Pkce::challenge((new Pkce())->newVerifier());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);
        $this->assertStringNotContainsString('=', $challenge);
    }
}
