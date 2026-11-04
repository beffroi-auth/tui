<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Amp\DeferredFuture;
use Amp\Future;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\TokenEndpoint;
use Beffroi\Tui\Protocol\TokenResponse;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use function sprintf;

/**
 * The tokens an administrator's session holds, the last ones the token endpoint answered. The access token is
 * refreshed before it expires, and once more when the administration API refuses it, with the refresh token of the
 * sign-in (RFC 6749 §6); a session the provider handed no refresh token keeps its access token until it expires, and
 * is signed in again after that.
 *
 * One refresh is made at a time: a request that finds one under way waits for it on the event loop rather than spending
 * the refresh token a second time, so that two sections read at once refresh the session once.
 */
final class HeldTokens
{
    /**
     * The seconds before its expiry an access token is refreshed, so that no request reaches the API with a token
     * that expires on its way there.
     */
    private const int MARGIN = 30;

    /**
     * @var Future<null>|null the refresh under way
     */
    private ?Future $refreshing = null;

    public function __construct(
        private TokenResponse $tokens,
        private DateTimeImmutable $obtainedAt,
        private readonly TokenEndpoint $endpoint,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The tokens held now, which are the ones handed back to the provider when the session ends.
     */
    public function tokens(): TokenResponse
    {
        return $this->tokens;
    }

    /**
     * When the access token held stops being worth anything, null when the provider did not say (RFC 6749 §5.1).
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->tokens->expiresIn === null
            ? null
            : $this->obtainedAt->add(new DateInterval(sprintf('PT%dS', $this->tokens->expiresIn)));
    }

    /**
     * An access token for the next request: the one held, refreshed first when it expires within the margin and the
     * session holds a refresh token.
     *
     * @throws ProtocolException when the provider refuses the refresh
     */
    public function accessToken(): string
    {
        $this->refreshing?->await();
        $expiresAt = $this->expiresAt();
        if ($expiresAt !== null && $this->tokens->refreshToken !== null && ($expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) <= self::MARGIN) {
            $this->refresh($this->tokens->refreshToken);
        }

        return $this->tokens->accessToken;
    }

    /**
     * The API refused the access token given: another one is obtained, unless one was obtained since that token was
     * given out.
     *
     * @return bool whether the session now holds another access token than the refused one
     *
     * @throws ProtocolException when the provider refuses the refresh
     */
    public function replace(string $refused): bool
    {
        $this->refreshing?->await();
        if ($this->tokens->accessToken !== $refused) {
            return true;
        }
        if ($this->tokens->refreshToken === null) {
            return false;
        }
        $this->refresh($this->tokens->refreshToken);

        return true;
    }

    /**
     * @throws ProtocolException
     */
    private function refresh(string $refreshToken): void
    {
        $refreshing = new DeferredFuture();
        $this->refreshing = $refreshing->getFuture();

        try {
            $obtainedAt = DateTimeImmutable::createFromInterface($this->clock->now());
            $this->tokens = $this->endpoint->refresh($refreshToken)->asRefreshOf($this->tokens);
            $this->obtainedAt = $obtainedAt;
        } finally {
            $this->refreshing = null;
            $refreshing->complete();
        }
    }
}
