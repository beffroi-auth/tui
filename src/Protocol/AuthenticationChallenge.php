<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * The challenges a protected resource answers a refused request with, in its `WWW-Authenticate` header (RFC 9110
 * §11.6.1): a resource refuses an access token there, with the "error" and the "error_description" of RFC 6750 §3,
 * and asks there for a nonce in the next proof (RFC 9449 §9), where the token endpoint says both in its body.
 *
 * A parameter is read as RFC 9110 §11.2 writes it, a token or a quoted string, and a quoted string is unquoted
 * (§5.6.4). What is read is the resource's words: a screen hands them to the terminal through `TerminalText`.
 */
final readonly class AuthenticationChallenge
{
    /**
     * The value of the parameter in the first challenge that carries it, null when none does.
     *
     * @param string $challenges the values of the header, joined by commas as a list of them is (RFC 9110 §5.3)
     */
    public static function parameter(string $challenges, string $name): ?string
    {
        $pattern = sprintf(
            '/(?:^|[\s,])%s[ \t]*=[ \t]*(?:"((?:[^"\\\\]|\\\\.)*)"|([!#$%%&\'*+.^_`|~0-9A-Za-z-]+))/',
            preg_quote($name, '/'),
        );
        if (preg_match($pattern, $challenges, $parameter) !== 1) {
            return null;
        }
        if (($parameter[2] ?? '') !== '') {
            return $parameter[2];
        }

        return preg_replace('/\\\\(.)/s', '$1', $parameter[1]) ?? $parameter[1];
    }
}
