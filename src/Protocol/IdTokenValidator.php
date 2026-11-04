<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use Beffroi\Tui\Provider\ProviderMetadata;
use function array_is_list;
use function array_key_exists;
use function decbin;
use function hash_equals;
use function in_array;
use InvalidArgumentException;
use function is_array;
use function is_string;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\EdDSA;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use function ord;
use Psr\Clock\ClockInterface;
use function sodium_base642bin;
use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;
use SodiumException;
use function sprintf;
use function strlen;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The ID token of a sign-in, held to OIDC Core §3.1.3.7 before anything in it names the administrator.
 *
 * It is a JWS and not an encrypted one; it is signed with the algorithm this client registered, one of those
 * FAPI 2.0 Security Profile §5.4.1 allows and never "none", under a key of the provider's JWK Set named by its
 * "kid", an RSA one of 2048 bits at least; its issuer is the provider's; this client is its audience and the only
 * one (item 3: an audience this client does not trust is refused, and it trusts none but itself), and "azp" names
 * it when it is there; it has not expired, it was not issued in the future, and it carries the "nonce" of the
 * request (item 11). The class of the authentication is the phishing-resistant one the request asked for as
 * essential (item 12, decision 0038), or the hardware-bound one, which is that class and more (OpenID Connect EAP
 * ACR Values 1.0).
 *
 * The clocks of two machines are allowed a minute between them, and nothing more.
 */
final readonly class IdTokenValidator
{
    /**
     * The algorithms of FAPI 2.0 Security Profile §5.4.1, in the order this program registers them: the one its
     * own keys sign with first.
     *
     * @var list<string>
     */
    public const array ALGORITHMS = ['ES256', 'EdDSA', 'PS256'];

    /**
     * What the "acr" of an administrator's session may be.
     *
     * @var list<string>
     */
    public const array AUTHENTICATION_CLASSES = [AuthorizationRequest::PHISHING_RESISTANT, 'phrh'];

    private const int LEEWAY = 60;

    /**
     * FAPI 2.0 Security Profile §5.4.1: an RSA key of 2048 bits at least.
     */
    private const int SMALLEST_RSA_MODULUS = 2048;

    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProtocolException when the ID token does not hold, or the keys of the provider cannot be read
     */
    public function validate(string $idToken, ProviderMetadata $provider, string $clientId, string $algorithm, string $nonce): Identity
    {
        try {
            $jws = new CompactSerializer()->unserialize($idToken);
        } catch (InvalidArgumentException $exception) {
            throw new RejectedAnswerException('The ID token is not a signed JWT in the compact serialization (OIDC Core §2).', previous: $exception);
        }
        $header = new Document($jws->getSignature(0)->getProtectedHeader());

        try {
            $signedWith = $header->string('alg');
            $keyId = $header->string('kid');
            if (array_key_exists('crit', $header->members)) {
                throw new RejectedAnswerException('The ID token names header parameters it must be understood by, which this program does not read (RFC 7515 §4.1.11).');
            }
            if ($signedWith !== $algorithm) {
                throw new RejectedAnswerException(sprintf(
                    'The ID token is signed with "%s" and this client registered "%s" (OIDC Core §3.1.3.7, item 7).',
                    $signedWith,
                    $algorithm,
                ));
            }
            $keys = $this->keysOf($provider);
            if (! $keys->has($keyId)) {
                throw new RejectedAnswerException(sprintf('The ID token is signed by a key the provider does not publish, "%s".', $keyId));
            }
            $key = $keys->get($keyId);
            if ($key->has('n') && self::bitsOf($key->get('n')) < self::SMALLEST_RSA_MODULUS) {
                throw new RejectedAnswerException(sprintf(
                    'The ID token is signed by an RSA key of fewer than %d bits (FAPI 2.0 Security Profile §5.4.1).',
                    self::SMALLEST_RSA_MODULUS,
                ));
            }
            if (! self::verifies($jws, $key)) {
                throw new RejectedAnswerException('The signature of the ID token does not verify (OIDC Core §3.1.3.7, item 6).');
            }

            return $this->identityOf(Document::decode($jws->getPayload() ?? '', 'payload of the ID token'), $provider->issuer, $clientId, $nonce);
        } catch (UnreadableDocumentException $exception) {
            throw new RejectedAnswerException(sprintf('The ID token is not readable: %s', $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * The length of an RSA modulus, the base64url of its octets without leading zeros (RFC 7518 §6.3.1.1).
     */
    private static function bitsOf(mixed $modulus): int
    {
        if (! is_string($modulus) || $modulus === '') {
            return 0;
        }

        try {
            $octets = sodium_base642bin($modulus, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return 0;
        }
        $leading = ord($octets[0] ?? "\0");

        return $leading === 0 ? 0 : ((strlen($octets) - 1) * 8) + strlen(decbin($leading));
    }

    /**
     * A key that is not one of a verification under the algorithm of the token verifies nothing.
     */
    private static function verifies(JWS $jws, JWK $key): bool
    {
        try {
            return new JWSVerifier(new AlgorithmManager([new PS256(), new ES256(), new EdDSA()]))->verifyWithKey($jws, $key, 0);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @throws RejectedAnswerException
     * @throws UnreadableDocumentException
     */
    private function identityOf(Document $claims, string $issuer, string $clientId, string $nonce): Identity
    {
        if ($claims->string('iss') !== $issuer) {
            throw new RejectedAnswerException('The ID token was issued by another issuer (OIDC Core §3.1.3.7, item 2).');
        }
        $audiences = $claims->members['aud'] ?? null;
        if ($audiences !== $clientId && $audiences !== [$clientId]) {
            throw new RejectedAnswerException('The ID token is addressed to another audience than this client alone (OIDC Core §3.1.3.7, item 3).');
        }
        $authorizedParty = $claims->nullableString('azp');
        if ($authorizedParty !== null && $authorizedParty !== $clientId) {
            throw new RejectedAnswerException('The ID token was issued to another party (OIDC Core §3.1.3.7, item 5).');
        }
        $now = $this->clock->now()->getTimestamp();
        $expiresAt = $claims->nullableInt('exp') ?? throw new RejectedAnswerException('The ID token carries no "exp" (OIDC Core §2).');
        if ($now > ($expiresAt + self::LEEWAY)) {
            throw new RejectedAnswerException('The ID token has expired (OIDC Core §3.1.3.7, item 9).');
        }
        $issuedAt = $claims->nullableInt('iat') ?? throw new RejectedAnswerException('The ID token carries no "iat" (OIDC Core §2).');
        if ($issuedAt > ($now + self::LEEWAY)) {
            throw new RejectedAnswerException('The ID token was issued in the future (OIDC Core §3.1.3.7, item 10).');
        }
        if (! hash_equals($nonce, $claims->nullableString('nonce') ?? '')) {
            throw new RejectedAnswerException('The ID token does not carry the nonce of this request (OIDC Core §3.1.3.7, item 11).');
        }
        $class = $claims->nullableString('acr');
        if ($class === null || ! in_array($class, self::AUTHENTICATION_CLASSES, true)) {
            throw new RejectedAnswerException(sprintf(
                'The session was not opened with a phishing-resistant proof ("acr" %s), which an administrator signs in with (decision 0038).',
                $class ?? 'absent',
            ));
        }

        return new Identity(
            $claims->string('sub'),
            $class,
            $claims->strings('amr'),
            $claims->nullableInt('auth_time'),
            $claims->nullableString('name'),
            $claims->nullableString('preferred_username'),
        );
    }

    /**
     * The JWK Set the metadata named, read again at every sign-in: a key the provider rotated in since the
     * last one is in it.
     *
     * @throws ProtocolException
     */
    private function keysOf(ProviderMetadata $provider): JWKSet
    {
        try {
            $response = $this->http->request('GET', $provider->jwksUri, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new UnreachableEndpointException(
                sprintf('The JWK Set of the provider cannot be fetched: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
        if ($status !== 200) {
            throw new RejectedAnswerException(sprintf('The JWK Set of the provider answered %d.', $status));
        }

        try {
            $members = Document::decode($body, 'JWK Set')->members;
            $keys = $members['keys'] ?? null;
            if (! is_array($keys) || ! array_is_list($keys)) {
                throw new RejectedAnswerException('The JWK Set of the provider carries no list of keys (RFC 7517 §5).');
            }
            $signing = [];
            foreach ($keys as $key) {
                $use = is_array($key) ? $key['use'] ?? 'sig' : null;
                if (is_array($key) && is_string($key['kid'] ?? null) && $use === 'sig') {
                    $signing[] = $key;
                }
            }

            return JWKSet::createFromKeyData(['keys' => $signing]);
        } catch (UnreadableDocumentException|InvalidArgumentException $exception) {
            throw new RejectedAnswerException(sprintf('The JWK Set of the provider is not readable: %s', $exception->getMessage()), previous: $exception);
        }
    }
}
