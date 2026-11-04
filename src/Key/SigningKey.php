<?php

declare(strict_types=1);

namespace Beffroi\Tui\Key;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use InvalidArgumentException;
use function is_string;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * A key pair this program signs with, ES256 on P-256, one of the three algorithms FAPI 2.0 Security Profile
 * §5.4.1 allows. It plays two parts and is the same object in both: the key the client authenticates with
 * (`private_key_jwt`, OIDC Core §9), drawn when the client registers, kept in its profile and published to the
 * provider as the only key of the client; and the key its tokens are bound to (RFC 9449), drawn when the
 * program starts and held in memory only, so that a token is worth nothing outside the run that asked for it.
 *
 * Its identifier is its thumbprint (RFC 7638), which names the key without saying anything else of it.
 */
final readonly class SigningKey
{
    public const string ALGORITHM = 'ES256';

    private const string CURVE = 'P-256';

    private function __construct(
        private JWK $jwk,
    ) {
    }

    public static function generate(): self
    {
        $drawn = JWKFactory::createECKey(self::CURVE, [
            'alg' => self::ALGORITHM,
            'use' => 'sig',
        ]);

        return new self(new JWK([...$drawn->all(), 'kid' => $drawn->thumbprint('sha256')]));
    }

    /**
     * The private key a profile kept, refused when it is not the kind of key this program draws.
     *
     * @throws UnreadableDocumentException when the document is not a private EC key on P-256 for ES256
     */
    public static function fromDocument(Document $document): self
    {
        if ($document->nullableString('kty') !== 'EC' || $document->nullableString('crv') !== self::CURVE) {
            throw new UnreadableDocumentException('The key of the profile must be an EC key on P-256.');
        }
        $algorithm = $document->nullableString('alg');
        if ($algorithm !== null && $algorithm !== self::ALGORITHM) {
            throw new UnreadableDocumentException('The key of the profile must be an ES256 key.');
        }
        foreach (['x', 'y', 'd'] as $member) {
            $document->string($member);
        }

        try {
            return new self(new JWK($document->members));
        } catch (InvalidArgumentException $exception) {
            throw new UnreadableDocumentException('The key of the profile is not a JWK.', previous: $exception);
        }
    }

    /**
     * The private key, as the profile keeps it.
     *
     * @return array<array-key, mixed>
     */
    public function toDocument(): array
    {
        return $this->jwk->all();
    }

    /**
     * What a verifier is shown of it, the private members left out.
     *
     * @return array<array-key, mixed>
     */
    public function publicJwk(): array
    {
        return $this->jwk->toPublic()->all();
    }

    /**
     * RFC 7638 §3, under SHA-256, base64url: what a provider compares a token's "cnf.jkt" against (RFC 9449 §6.1).
     */
    public function thumbprint(): string
    {
        return $this->jwk->thumbprint('sha256');
    }

    /**
     * The "kid" the key was published under, its thumbprint when it was drawn here.
     */
    public function id(): string
    {
        $id = $this->jwk->has('kid') ? $this->jwk->get('kid') : null;

        return is_string($id) && $id !== '' ? $id : $this->thumbprint();
    }

    /**
     * A JWS in the compact serialization, signed with this key under ES256, the algorithm written into the
     * protected header whatever the caller put there.
     *
     * @param array<string, mixed> $protectedHeader
     * @param array<string, mixed> $claims
     */
    public function sign(array $protectedHeader, array $claims): string
    {
        $jws = new JWSBuilder(new AlgorithmManager([new ES256()]))
            ->create()
            ->withPayload(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->addSignature($this->jwk, [...$protectedHeader, 'alg' => self::ALGORITHM])
            ->build();

        return new CompactSerializer()->serialize($jws, 0);
    }
}
