<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function random_bytes;

/**
 * A value drawn for one exchange and never again: the "state" and the "nonce" of an authorization request, its
 * PKCE verifier, the "jti" of an assertion or of a proof. Thirty-two random octets, base64url without padding,
 * which is the 43 characters RFC 7636 §7.1 recommends for a verifier and under the 64 FAPI 2.0 Security Profile
 * §5.3.3.2 (item 7) asks a nonce to stay within.
 */
final readonly class Drawn
{
    private const int OCTETS = 32;

    public static function value(): string
    {
        return Base64Url::encode(random_bytes(self::OCTETS));
    }
}
