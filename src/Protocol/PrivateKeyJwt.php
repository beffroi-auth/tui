<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Key\SigningKey;
use Psr\Clock\ClockInterface;

/**
 * The client authenticates with an assertion signed by its key (OIDC Core §9, RFC 7523 §2.2): "iss" and "sub"
 * are its identifier, "aud" is the issuer identifier of the provider, as a string and not as an item of an
 * array (FAPI 2.0 Security Profile §5.3.3.1, item 5), so that an assertion made for this provider is worth
 * nothing to another one; "jti" is drawn for this request and "exp" is a minute away (RFC 7523 §3).
 */
final readonly class PrivateKeyJwt implements ClientAuthenticationInterface
{
    public const string ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const int LIFETIME = 60;

    public function __construct(
        private string $clientId,
        private SigningKey $key,
        private string $issuer,
        private ClockInterface $clock,
    ) {
    }

    public function parameters(): array
    {
        $now = $this->clock->now()->getTimestamp();

        return [
            'client_id' => $this->clientId,
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion' => $this->key->sign(
                [
                    'kid' => $this->key->id(),
                ],
                [
                    'iss' => $this->clientId,
                    'sub' => $this->clientId,
                    'aud' => $this->issuer,
                    'jti' => Drawn::value(),
                    'iat' => $now,
                    'exp' => $now + self::LIFETIME,
                ],
            ),
        ];
    }
}
