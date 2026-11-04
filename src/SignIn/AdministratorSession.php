<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Beffroi\Tui\Api\AdministrationApi;
use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Protocol\DpopProofs;
use Beffroi\Tui\Protocol\Identity;
use Beffroi\Tui\Protocol\ProviderEndpoint;
use Beffroi\Tui\Protocol\Revocation;
use Beffroi\Tui\Protocol\TokenEndpoint;
use Beffroi\Tui\Protocol\TokenResponse;
use Beffroi\Tui\Provider\ProviderMetadata;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An administrator signed in through this program: who, by what proof, under which tokens, and the key those
 * tokens are bound to. It lives in the memory of the run and ends with it: ending it hands back to the provider the
 * tokens it holds then, refreshed or not (`Revocation`). A token the provider cannot take back stays bound to a key
 * that only this run held, and that key is gone with it.
 */
final readonly class AdministratorSession
{
    private HeldTokens $tokens;

    public function __construct(
        public Profile $profile,
        public ProviderMetadata $provider,
        public Identity $identity,
        TokenResponse $tokens,
        DateTimeImmutable $obtainedAt,
        public DpopProofs $proofs,
        private ProviderEndpoint $endpoint,
        private HttpClientInterface $http,
        ClockInterface $clock,
    ) {
        $this->tokens = new HeldTokens($tokens, $obtainedAt, new TokenEndpoint($endpoint, $provider), $clock);
    }

    public function scope(): ?string
    {
        return $this->tokens->tokens()->scope;
    }

    /**
     * When the access token held stops being worth anything, null when the provider did not say (RFC 6749 §5.1).
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->tokens->expiresAt();
    }

    /**
     * How the screen names the administrator: the name the ID token carries, then the username, then the subject.
     */
    public function administratorName(): string
    {
        return $this->identity->displayName();
    }

    /**
     * The administration API of the instance, the one the profile names, read with the tokens of this session.
     */
    public function api(): AdministrationApi
    {
        return new AdministrationApi($this->http, $this->profile->administration, $this->proofs, $this->tokens);
    }

    /**
     * The tokens handed back to the provider.
     *
     * @return list<string> what the provider did not take back, in its words, or that it takes nothing back
     */
    public function end(): array
    {
        return new Revocation($this->endpoint, $this->provider)->handBack($this->tokens->tokens());
    }
}
