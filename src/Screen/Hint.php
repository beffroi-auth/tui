<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use function preg_match;
use function sprintf;

/**
 * The identifier of a string of the catalogue named after a word the instance answered, the "error" of a refusal
 * (RFC 6749 §4.1.2.1, §5.2, RFC 6750 §3.1, OIDC Core §3.1.2.6) a name of lowercase letters and underscores: the hint
 * of another refusal is a string added to the catalogue, and a word that is not such a name names no string at all.
 */
final readonly class Hint
{
    public static function identifier(string $prefix, ?string $word): ?string
    {
        return $word !== null && preg_match('/^[a-z_]{1,64}$/', $word) === 1 ? sprintf('%s.%s', $prefix, $word) : null;
    }
}
