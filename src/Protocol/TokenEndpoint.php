<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Provider\ProviderMetadata;
use SensitiveParameter;

/**
 * The token endpoint, authenticated as the client and proven by the key of this run, so that every access token
 * it answers is bound to that key (RFC 9449 §5): the code of a sign-in exchanged with its verifier (RFC 6749
 * §4.1.3, RFC 7636 §4.5), then the refresh token spent for a new access token (RFC 6749 §6), by the client it was
 * issued to, which authenticates as it did for the code.
 */
final readonly class TokenEndpoint
{
    private const string ENDPOINT = 'token endpoint';

    public function __construct(
        private ProviderEndpoint $endpoint,
        private ProviderMetadata $provider,
    ) {
    }

    /**
     * @throws ProtocolException
     */
    public function exchange(#[SensitiveParameter] string $code, AuthorizationRequest $request): TokenResponse
    {
        $answer = $this->endpoint->post(
            self::ENDPOINT,
            $this->provider->tokenEndpoint,
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $request->redirectUri,
                'code_verifier' => $request->codeVerifier,
            ],
            true,
        );

        return TokenResponse::fromDocument($answer->expect(200));
    }

    /**
     * RFC 6749 §6: the scope and the resources are those originally granted, so the request names neither.
     *
     * @throws ProtocolException
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): TokenResponse
    {
        $answer = $this->endpoint->post(
            self::ENDPOINT,
            $this->provider->tokenEndpoint,
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            true,
        );

        return TokenResponse::fromDocument($answer->expect(200));
    }
}
