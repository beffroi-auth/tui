<?php

declare(strict_types=1);

namespace Beffroi\Tui\Profile;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use Beffroi\Tui\Key\SigningKey;
use SensitiveParameter;

/**
 * What this program keeps of one instance it administers, written once when it registered there and read at
 * every start: the issuer, the client it is there, the one redirect URI it registered, the key it
 * authenticates with, the algorithm its ID tokens are signed with, what RFC 7592 handed it to manage its own
 * registration, and the certificate authority it trusts that instance under when it is not the system's.
 *
 * No token is kept: what a sign-in obtains lives in the memory of the run that obtained it.
 */
final readonly class Profile
{
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $redirectUri,
        public SigningKey $clientKey,
        public string $idTokenSigningAlgorithm,
        public string $administration,
        public ?string $registrationClientUri = null,
        #[SensitiveParameter]
        public ?string $registrationAccessToken = null,
        public ?string $certificateAuthority = null,
    ) {
    }

    /**
     * @throws UnreadableDocumentException when a member is missing or is not what it must be
     */
    public static function fromDocument(Document $document): self
    {
        $key = $document->nested('client_key') ?? throw new UnreadableDocumentException('The profile carries no "client_key".');

        return new self(
            $document->string('issuer'),
            $document->string('client_id'),
            $document->string('redirect_uri'),
            SigningKey::fromDocument($key),
            $document->string('id_token_signed_response_alg'),
            $document->nullableString('administration') ?? throw new UnreadableDocumentException('it names no administration API, as a profile registered before this program asked for one does: register this machine again, naming the API ("register <issuer> <administration API>").'),
            $document->nullableString('registration_client_uri'),
            $document->nullableString('registration_access_token'),
            $document->nullableString('certificate_authority'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'issuer' => $this->issuer,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'client_key' => $this->clientKey->toDocument(),
            'id_token_signed_response_alg' => $this->idTokenSigningAlgorithm,
            'administration' => $this->administration,
            'registration_client_uri' => $this->registrationClientUri,
            'registration_access_token' => $this->registrationAccessToken,
            'certificate_authority' => $this->certificateAuthority,
        ];
    }
}
