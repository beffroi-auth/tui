<?php

declare(strict_types=1);

namespace Beffroi\Tui\Provider;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use Beffroi\Tui\Key\SigningKey;
use Beffroi\Tui\Protocol\AuthorizationRequest;
use Beffroi\Tui\Protocol\IdTokenValidator;
use function array_intersect;
use function array_values;
use function in_array;
use function rtrim;
use function sprintf;
use function str_starts_with;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The metadata of a provider, read from the document it publishes (OpenID Connect Discovery 1.0 §4) and held
 * to what an administrator's sign-in needs before anything is sent to it: this program talks to a provider
 * that announces each of these, and to no other, whatever its configuration says. It is the client side of
 * decision 0006: there is no option to do it with less.
 *
 * - the issuer is the one this program was given, character for character (Discovery §4.3, FAPI 2.0 Security
 *   Profile §5.3.3.1, item 11), and every endpoint is under HTTPS;
 * - requests are pushed (RFC 9126, FAPI 2.0 §5.3.3.2, item 2), PKCE is S256 (RFC 7636 §4.2, item 3), the
 *   authorization response names its issuer (RFC 9207 §3, item 5);
 * - the client authenticates with `private_key_jwt` signed under ES256 (OIDC Core §9, §5.3.3.1 item 2), and its
 *   tokens are bound by DPoP proofs under ES256 (RFC 9449 §5.1, item 1);
 * - the "acr" `phr` is offered, the class of a phishing-resistant proof (OpenID Connect EAP ACR Values 1.0),
 *   which decision 0038 holds an administrator's session to;
 * - an ID token is signed with an algorithm FAPI 2.0 §5.4.1 allows.
 */
final readonly class ProviderMetadataReader
{
    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * @throws UnsuitableProviderException when the document cannot be read or does not announce what is needed
     */
    public function read(string $issuer): ProviderMetadata
    {
        self::assertSecure('issuer', $issuer);
        $location = rtrim($issuer, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->http->request('GET', $location, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new UnsuitableProviderException(sprintf('The metadata of %s cannot be fetched: %s', $issuer, $exception->getMessage()), previous: $exception);
        }
        if ($status !== 200) {
            throw new UnsuitableProviderException(sprintf('The metadata of %s answered %d at %s.', $issuer, $status, $location));
        }

        try {
            return self::metadataOf(Document::decode($body, 'metadata document'), $issuer);
        } catch (UnreadableDocumentException $exception) {
            throw new UnsuitableProviderException(sprintf('The metadata of %s are not readable: %s', $issuer, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @throws UnsuitableProviderException
     * @throws UnreadableDocumentException
     */
    private static function metadataOf(Document $document, string $issuer): ProviderMetadata
    {
        $announced = $document->string('issuer');
        if ($announced !== $issuer) {
            throw new UnsuitableProviderException(sprintf(
                'The metadata of %s name another issuer, "%s" (OpenID Connect Discovery 1.0 §4.3).',
                $issuer,
                $announced,
            ));
        }
        if (! $document->isTrue('authorization_response_iss_parameter_supported')) {
            throw new UnsuitableProviderException('The provider does not say that its authorization responses name their issuer (RFC 9207 §3), which is what tells this program a response came from it.');
        }
        self::assertAnnounces($document, 'code_challenge_methods_supported', 'S256');
        self::assertAnnounces($document, 'token_endpoint_auth_methods_supported', 'private_key_jwt');
        self::assertAnnounces($document, 'token_endpoint_auth_signing_alg_values_supported', SigningKey::ALGORITHM);
        self::assertAnnounces($document, 'dpop_signing_alg_values_supported', SigningKey::ALGORITHM);
        self::assertAnnounces($document, 'acr_values_supported', AuthorizationRequest::PHISHING_RESISTANT);
        $algorithms = array_values(array_intersect(IdTokenValidator::ALGORITHMS, $document->strings('id_token_signing_alg_values_supported')));
        if ($algorithms === []) {
            throw new UnsuitableProviderException('The provider signs ID tokens with none of PS256, ES256 and EdDSA (FAPI 2.0 Security Profile §5.4.1).');
        }

        return new ProviderMetadata(
            $issuer,
            self::endpoint($document, 'authorization_endpoint'),
            self::endpoint($document, 'pushed_authorization_request_endpoint'),
            self::endpoint($document, 'token_endpoint'),
            self::endpoint($document, 'jwks_uri'),
            $algorithms,
            self::optionalEndpoint($document, 'revocation_endpoint'),
            self::optionalEndpoint($document, 'registration_endpoint'),
        );
    }

    /**
     * @throws UnsuitableProviderException
     * @throws UnreadableDocumentException
     */
    private static function assertAnnounces(Document $document, string $member, string $value): void
    {
        if (! in_array($value, $document->strings($member), true)) {
            throw new UnsuitableProviderException(sprintf('The provider does not list "%s" in "%s".', $value, $member));
        }
    }

    /**
     * @throws UnsuitableProviderException
     * @throws UnreadableDocumentException
     */
    private static function endpoint(Document $document, string $member): string
    {
        $endpoint = $document->nullableString($member);
        if ($endpoint === null) {
            throw new UnsuitableProviderException(sprintf('The provider announces no "%s".', $member));
        }
        self::assertSecure($member, $endpoint);

        return $endpoint;
    }

    /**
     * @throws UnsuitableProviderException
     * @throws UnreadableDocumentException
     */
    private static function optionalEndpoint(Document $document, string $member): ?string
    {
        return $document->nullableString($member) === null ? null : self::endpoint($document, $member);
    }

    /**
     * @throws UnsuitableProviderException
     */
    private static function assertSecure(string $member, string $url): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new UnsuitableProviderException(sprintf('The %s "%s" is not an HTTPS URL, and this program sends nothing over another one.', $member, $url));
        }
    }
}
