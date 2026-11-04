<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function sodium_bin2base64;
use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

/**
 * base64url without padding (RFC 4648 §5, RFC 7515 §2), in constant time, which is how every value drawn and
 * every hash this program sends is written.
 */
final readonly class Base64Url
{
    public static function encode(string $octets): string
    {
        return $octets === '' ? '' : sodium_bin2base64($octets, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
