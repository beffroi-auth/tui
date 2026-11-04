<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Key\SigningKey;
use function hash;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use Psr\Clock\ClockInterface;
use function sprintf;
use function strcspn;
use function substr;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The proofs of possession of the key the tokens of this run are bound to (RFC 9449), one per request, and the
 * nonces the servers handed out for them.
 *
 * A proof names the method and the URI it was made for, without query and fragment (§4.2), and carries the
 * hash of the access token it accompanies to a resource ("ath", §4.2, §7.1). A server may hand out a nonce in
 * a `DPoP-Nonce` header (§8 for the provider, §9 for a resource server), and the next proof sent there
 * carries the last one it handed out, which FAPI 2.0 Security Profile §5.3.3.1 (item 8) asks a client to
 * support. A nonce is kept by origin, the server that issued it, and nowhere else.
 */
final class DpopProofs
{
    /**
     * @var array<string, string> the last nonce of each origin
     */
    private array $nonces = [];

    public function __construct(
        private readonly SigningKey $key,
        private readonly ClockInterface $clock,
    ) {
    }

    public function proof(string $method, string $uri, ?string $accessToken = null): string
    {
        $claims = [
            'jti' => Drawn::value(),
            'htm' => $method,
            'htu' => substr($uri, 0, strcspn($uri, '?#')),
            'iat' => $this->clock->now()->getTimestamp(),
        ];
        if ($accessToken !== null) {
            $claims['ath'] = Base64Url::encode(hash('sha256', $accessToken, true));
        }
        $nonce = $this->nonces[self::origin($uri)] ?? null;
        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        return $this->key->sign(
            [
                'typ' => 'dpop+jwt',
                'jwk' => $this->key->publicJwk(),
            ],
            $claims,
        );
    }

    /**
     * RFC 9449 §8.2 and §9: the nonce a response carries replaces the one this origin handed out before. A
     * value that is not the `1*NQCHAR` of §8.1 is no nonce.
     */
    public function remember(string $uri, ResponseInterface $response): void
    {
        $nonce = $response->getHeaders(false)['dpop-nonce'][0] ?? null;
        if (is_string($nonce) && preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $nonce) === 1) {
            $this->nonces[self::origin($uri)] = $nonce;
        }
    }

    /**
     * The key the tokens are bound to, as the provider names it in "cnf.jkt" (RFC 9449 §6.1).
     */
    public function thumbprint(): string
    {
        return $this->key->thumbprint();
    }

    private static function origin(string $uri): string
    {
        $parts = parse_url($uri);
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port = $parts['port'] ?? null;
        if (! is_int($port)) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }
}
