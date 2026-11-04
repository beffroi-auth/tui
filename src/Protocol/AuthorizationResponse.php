<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function array_key_exists;
use function explode;
use function hash_equals;
use function sprintf;
use function urldecode;

/**
 * The authorization response the browser brought back to the loopback interface, read in an order that lets
 * nothing of it be used before it is known to be the answer to this request from this provider.
 *
 * First its issuer, which must be there and be this provider's, character for character (RFC 9207 §2.4, FAPI
 * 2.0 Security Profile §5.3.3.2, item 5): a response without one is refused, the provider having said it
 * names one, and an error from elsewhere is not taken for this provider's. Then the "state" this request
 * drew (RFC 6749 §10.12). Only then the refusal the provider answered (§4.1.2.1), or the code (§4.1.2). A
 * parameter written twice is refused rather than read one way or the other (§3.1).
 */
final readonly class AuthorizationResponse
{
    /**
     * @throws RejectedAnswerException when the response is not the answer of this provider to this request
     * @throws ProviderRefusalException when it is, and refuses
     */
    public static function codeOf(string $query, string $issuer, string $state): string
    {
        $parameters = self::parametersOf($query);
        $answeredIssuer = $parameters['iss'] ?? null;
        if ($answeredIssuer === null) {
            throw new RejectedAnswerException('The authorization response names no issuer, which the provider said every one of its responses does (RFC 9207 §2.4).');
        }
        if ($answeredIssuer !== $issuer) {
            throw new RejectedAnswerException(sprintf('The authorization response comes from another issuer than %s (RFC 9207 §2.4).', $issuer));
        }
        if (! hash_equals($state, $parameters['state'] ?? '')) {
            throw new RejectedAnswerException('The authorization response does not carry the state of this request (RFC 6749 §10.12).');
        }
        if (array_key_exists('error', $parameters)) {
            throw new ProviderRefusalException('authorization endpoint', null, $parameters['error'], $parameters['error_description'] ?? null);
        }
        $code = $parameters['code'] ?? '';
        if ($code === '') {
            throw new RejectedAnswerException('The authorization response carries neither a code nor an error (RFC 6749 §4.1.2).');
        }

        return $code;
    }

    /**
     * Whether a query is the response to the request that drew this state, the one thing the loopback interface
     * waits for: everything else the response says is read by `codeOf()`.
     */
    public static function carriesState(string $query, string $state): bool
    {
        try {
            return hash_equals($state, self::parametersOf($query)['state'] ?? '');
        } catch (RejectedAnswerException) {
            return false;
        }
    }

    /**
     * application/x-www-form-urlencoded, each name once.
     *
     * @throws RejectedAnswerException when a parameter is written twice
     *
     * @return array<string, string>
     */
    private static function parametersOf(string $query): array
    {
        $parameters = [];
        if ($query === '') {
            return $parameters;
        }
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = [...explode('=', $pair, 2), ''];
            $name = urldecode($name);
            if (array_key_exists($name, $parameters)) {
                throw new RejectedAnswerException(sprintf('The authorization response carries "%s" twice (RFC 6749 §3.1).', $name));
            }
            $parameters[$name] = urldecode($value);
        }

        return $parameters;
    }
}
