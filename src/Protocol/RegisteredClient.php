<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use SensitiveParameter;
use function sprintf;

/**
 * The client the provider registered (RFC 7591 §3.2.1), held to what this program asked for: a provider may
 * register a client with other values than it was sent, and a client registered with a secret, without pushed
 * requests or with another redirect URI is not one this program uses. What RFC 7592 §3 hands over to manage the
 * registration is kept with it.
 */
final readonly class RegisteredClient
{
    private function __construct(
        public string $clientId,
        public ?string $registrationClientUri,
        #[SensitiveParameter]
        public ?string $registrationAccessToken,
    ) {
    }

    /**
     * @throws RejectedAnswerException when the provider registered something else than what was asked
     */
    public static function fromDocument(Document $document, ClientRegistrationRequest $request): self
    {
        try {
            $method = $document->nullableString('token_endpoint_auth_method');
            if ($method !== 'private_key_jwt') {
                throw new RejectedAnswerException(sprintf(
                    'The provider registered the client to authenticate with "%s" and not with its key.',
                    $method ?? 'its default',
                ));
            }
            if ($document->strings('redirect_uris') !== [$request->redirectUri]) {
                throw new RejectedAnswerException('The provider registered other redirect URIs than the one of this program.');
            }
            if (! $document->isTrue('require_pushed_authorization_requests')) {
                throw new RejectedAnswerException('The provider registered the client without holding it to pushed requests (RFC 9126 §6).');
            }
            $algorithm = $document->nullableString('id_token_signed_response_alg');
            if ($algorithm !== $request->idTokenSigningAlgorithm) {
                throw new RejectedAnswerException(sprintf(
                    'The provider registered the client to receive ID tokens signed with "%s" and not "%s".',
                    $algorithm ?? 'its default',
                    $request->idTokenSigningAlgorithm,
                ));
            }

            return new self(
                $document->string('client_id'),
                $document->nullableString('registration_client_uri'),
                $document->nullableString('registration_access_token'),
            );
        } catch (UnreadableDocumentException $exception) {
            throw new RejectedAnswerException($exception->getMessage(), previous: $exception);
        }
    }
}
