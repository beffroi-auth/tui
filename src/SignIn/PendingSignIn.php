<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Amp\Cancellation;
use Amp\CancelledException;
use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Protocol\AuthorizationRequest;
use Beffroi\Tui\Protocol\AuthorizationResponse;
use Beffroi\Tui\Protocol\DpopProofs;
use Beffroi\Tui\Protocol\IdTokenValidator;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\ProviderEndpoint;
use Beffroi\Tui\Protocol\RejectedAnswerException;
use Beffroi\Tui\Protocol\Revocation;
use Beffroi\Tui\Protocol\TokenEndpoint;
use Beffroi\Tui\Provider\ProviderMetadata;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A sign-in whose browser is on its way: the URL it was sent to, and what turns the response it brings back
 * into an administrator's session once each step holds. The response is read before it is spent
 * (`AuthorizationResponse`), the code is exchanged with its verifier and a proof of the key the tokens are bound
 * to, and the ID token is validated, the name of the administrator included. The access token is addressed to the
 * administration API (RFC 8707 §2) and to nothing else of the provider, UserInfo included. Nothing of the sign-in is
 * used before the step that vouches for it, and the tokens of a sign-in a later step refuses are handed back.
 */
final readonly class PendingSignIn
{
    public function __construct(
        public string $authorizationUrl,
        private LoopbackListener $listener,
        private AuthorizationRequest $request,
        private Profile $profile,
        private ProviderMetadata $provider,
        private ProviderEndpoint $endpoint,
        private DpopProofs $proofs,
        private HttpClientInterface $http,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CancelledException when the cancellation is cancelled before the browser comes back
     * @throws CannotListenException when the loopback interface stops accepting connections
     * @throws ProtocolException when a step does not hold
     */
    public function complete(?Cancellation $cancellation = null): AdministratorSession
    {
        $state = $this->request->state;
        $query = $this->listener->awaitRedirection(static fn (string $query): bool => AuthorizationResponse::carriesState($query, $state), $cancellation);
        $code = AuthorizationResponse::codeOf($query, $this->provider->issuer, $this->request->state);
        $obtainedAt = DateTimeImmutable::createFromInterface($this->clock->now());
        $tokens = new TokenEndpoint($this->endpoint, $this->provider)->exchange($code, $this->request);

        try {
            $identity = new IdTokenValidator($this->http, $this->clock)->validate(
                $tokens->idToken ?? throw new RejectedAnswerException('The token endpoint answered no ID token (OIDC Core §3.1.3.3).'),
                $this->provider,
                $this->profile->clientId,
                $this->profile->idTokenSigningAlgorithm,
                $this->request->nonce,
            );

            return new AdministratorSession(
                $this->profile,
                $this->provider,
                $identity,
                $tokens,
                $obtainedAt,
                $this->proofs,
                $this->endpoint,
                $this->http,
                $this->clock,
            );
        } catch (ProtocolException $exception) {
            // the tokens of a sign-in that does not hold go back to the provider rather than stay valid
            new Revocation($this->endpoint, $this->provider)->handBack($tokens);

            throw $exception;
        }
    }

    /**
     * The sign-in given up before the browser came back: the port is let go.
     */
    public function abandon(): void
    {
        $this->listener->close();
    }
}
