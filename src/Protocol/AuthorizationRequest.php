<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function hash;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use SensitiveParameter;

/**
 * The authorization request of one sign-in, pushed and never put in a URL (RFC 9126): a code for this client,
 * its one redirect URI, and three values drawn for this request alone, the "state" that ties the response to it
 * (RFC 6749 §10.12), the "nonce" that ties the ID token to it (OIDC Core §3.1.2.1), and the PKCE verifier whose
 * S256 challenge it carries (RFC 7636 §4.2, FAPI 2.0 Security Profile §5.3.3.2, item 4).
 *
 * The ID token is asked to carry the "acr" `phr` as an essential claim (OIDC Core §5.5.1.1): an administrator
 * signs in with a phishing-resistant proof whatever the floor of the tenant (decision 0038), and a login that
 * does not satisfy it ends in a refusal rather than in a session. The proof is asked again at every start of the
 * administration ("prompt" `login`, OIDC Core §3.1.2.1): a session the browser already holds was opened for
 * whatever it was opened for, perhaps by somebody else at this browser, and an administrator's session begins
 * with the administrator present. The pages of the sign-in are asked for in the language of this terminal
 * ("ui_locales", §3.1.2.1).
 */
final readonly class AuthorizationRequest
{
    /**
     * What an administrator's session asks for: the ID token, and the claims that name the administrator.
     */
    public const string SCOPE = 'openid profile';

    /**
     * The class of a phishing-resistant proof, a passkey synced or bound to its authenticator (OpenID Connect
     * EAP ACR Values 1.0), whose name a tenant cannot give to anything weaker.
     */
    public const string PHISHING_RESISTANT = 'phr';

    private function __construct(
        public string $clientId,
        public string $redirectUri,
        public string $locale,
        public string $resource,
        public string $state,
        #[SensitiveParameter]
        public string $nonce,
        #[SensitiveParameter]
        public string $codeVerifier,
    ) {
    }

    /**
     * @param string $resource the administration API the tokens are asked for (RFC 8707 §2), which the provider reserves to the administrators of the tenant (decision 0038)
     */
    public static function draw(string $clientId, string $redirectUri, string $locale, string $resource): self
    {
        return new self($clientId, $redirectUri, $locale, $resource, Drawn::value(), Drawn::value(), Drawn::value());
    }

    public function codeChallenge(): string
    {
        return self::s256($this->codeVerifier);
    }

    /**
     * RFC 7636 §4.2: BASE64URL-ENCODE(SHA256(ASCII(code_verifier))).
     */
    public static function s256(#[SensitiveParameter] string $codeVerifier): string
    {
        return Base64Url::encode(hash('sha256', $codeVerifier, true));
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => self::SCOPE,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_challenge' => $this->codeChallenge(),
            'code_challenge_method' => 'S256',
            // OIDC Core §5.5: the name of the administrator is asked of the ID token, the access token being addressed
            // to the administration API (RFC 8707 §2), and UserInfo serving a token addressed to the client alone
            'claims' => json_encode([
                'id_token' => [
                    'acr' => [
                        'essential' => true,
                        'values' => [self::PHISHING_RESISTANT],
                    ],
                    'name' => null,
                    'preferred_username' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            'prompt' => 'login',
            'ui_locales' => $this->locale,
            'resource' => $this->resource,
        ];
    }
}
