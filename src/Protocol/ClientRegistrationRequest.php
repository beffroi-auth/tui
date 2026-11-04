<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Key\SigningKey;

/**
 * The metadata this program registers itself under (RFC 7591 §2, OIDC Registration §2): a confidential client
 * of one installation, which RFC 8252 §8.4 allows a native application to be when its credential is provisioned
 * for that installation alone, here a key drawn on this machine, of which the provider holds the public half and
 * nobody else the private one. It authenticates with that key and never with a secret (decision 0038), starts
 * every request by pushing it (RFC 9126 §6), and is answered on the loopback interface, at the port it
 * registered and to which a confidential client stays held. Its ID tokens are signed with the algorithm it
 * names.
 */
final readonly class ClientRegistrationRequest
{
    public function __construct(
        public string $clientName,
        public string $redirectUri,
        public SigningKey $key,
        public string $idTokenSigningAlgorithm,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'client_name' => $this->clientName,
            'redirect_uris' => [$this->redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => AuthorizationRequest::SCOPE,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => [
                'keys' => [$this->key->publicJwk()],
            ],
            'id_token_signed_response_alg' => $this->idTokenSigningAlgorithm,
            'require_pushed_authorization_requests' => true,
        ];
    }
}
