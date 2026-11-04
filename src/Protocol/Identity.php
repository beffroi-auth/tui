<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

/**
 * Who signed in, as the ID token of the sign-in says it once `IdTokenValidator` holds it to the rules: the
 * subject, the class and the methods of the authentication (OIDC Core §2, RFC 8176), when it happened, and the
 * names the provider gave for this person.
 */
final readonly class Identity
{
    /**
     * @param list<string> $methods the "amr" of the ID token
     */
    public function __construct(
        public string $subject,
        public string $authenticationClass,
        public array $methods,
        public ?int $authenticatedAt,
        public ?string $name,
        public ?string $preferredUsername,
    ) {
    }

    /**
     * How a screen names this person: the name, then the username, then the subject.
     */
    public function displayName(): string
    {
        return $this->name ?? $this->preferredUsername ?? $this->subject;
    }
}
