<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Provider\ProviderMetadata;
use SensitiveParameter;

/**
 * The tokens this run no longer needs, handed back to the provider (RFC 7009 §2.1): when the administrator leaves,
 * and when a sign-in that obtained them does not hold, so that the end of the program is the end of its tokens at
 * the provider as well as in its memory. The refresh token goes first, whose revocation takes the access tokens of
 * its grant along when the provider can (§2.1); the provider answers 200 for a token it does not know as for one it
 * revoked (§2.2), and refuses a self-contained access token, which it cannot take back (§2.2.1).
 */
final readonly class Revocation
{
    private const string ENDPOINT = 'revocation endpoint';

    /**
     * RFC 7009 §2.2.1: the provider does not revoke tokens of that type.
     */
    private const string NOT_REVOKED = 'unsupported_token_type';

    public function __construct(
        private ProviderEndpoint $endpoint,
        private ProviderMetadata $provider,
    ) {
    }

    /**
     * RFC 7009 §2: a provider takes a refresh token back, and may not take an access token back. It says so with
     * "unsupported_token_type" (§2.2.1), which for an access token is no refusal to tell anybody: the token is bound to
     * a key this program drew for its run (RFC 9449 §6), which nobody holds once the run is over, and it expires on its
     * own. Any other refusal is told, in the words of the provider.
     *
     * @return list<string> what the provider did not take back, in its words, or that it takes nothing back
     */
    public function handBack(TokenResponse $tokens): array
    {
        if ($this->provider->revocationEndpoint === null) {
            return ['The provider announces no revocation endpoint (RFC 7009 §2): the tokens of this session stay valid until they expire.'];
        }
        $handedBack = $tokens->refreshToken === null
            ? ['access_token' => $tokens->accessToken]
            : ['refresh_token' => $tokens->refreshToken, 'access_token' => $tokens->accessToken];
        $refusals = [];
        foreach ($handedBack as $hint => $token) {
            try {
                $this->revoke($this->provider->revocationEndpoint, $token, $hint);
            } catch (ProviderRefusalException $refusal) {
                if ($hint !== 'access_token' || $refusal->error !== self::NOT_REVOKED) {
                    $refusals[] = $refusal->getMessage();
                }
            } catch (ProtocolException $exception) {
                $refusals[] = $exception->getMessage();
            }
        }

        return $refusals;
    }

    /**
     * @throws ProtocolException
     */
    private function revoke(string $endpoint, #[SensitiveParameter] string $token, string $hint): void
    {
        $answer = $this->endpoint->post(
            self::ENDPOINT,
            $endpoint,
            [
                'token' => $token,
                'token_type_hint' => $hint,
            ],
            false,
        );
        if ($answer->status !== 200) {
            throw $answer->refusal();
        }
    }
}
