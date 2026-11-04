<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use SensitiveParameter;
use function sprintf;
use function strcasecmp;

/**
 * The tokens of a successful answer of the token endpoint (RFC 6749 §5.1), held in memory and nowhere else.
 *
 * The access token is bound to the key of this run or it is refused: a provider that answers a bearer token
 * did not bind it (RFC 9449 §5), and a token that whoever reads it can use is not one this program holds
 * (FAPI 2.0 Security Profile §5.3.3.1, item 1; decision 0038). The type is compared without regard to case
 * (RFC 6749 §5.1).
 */
final readonly class TokenResponse
{
    /**
     * RFC 6749 §5.1 leaves the lifetime to the provider; one beyond a year is not a figure this program computes with.
     */
    private const int LONGEST_LIFETIME = 31_536_000;

    private function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        public ?int $expiresIn,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public ?string $idToken,
        public ?string $scope,
    ) {
    }

    /**
     * @throws RejectedAnswerException when the answer is not one of a bound token
     */
    public static function fromDocument(Document $document): self
    {
        try {
            $type = $document->string('token_type');
            if (strcasecmp($type, 'DPoP') !== 0) {
                throw new RejectedAnswerException(sprintf(
                    'The provider answered a token of the type "%s", which is not bound to the key of this program (RFC 9449 §5).',
                    $type,
                ));
            }
            $expiresIn = $document->nullableInt('expires_in');
            if ($expiresIn !== null && $expiresIn < 1) {
                throw new RejectedAnswerException('The provider answered a token that has already expired (RFC 6749 §5.1).');
            }
            if ($expiresIn !== null && $expiresIn > self::LONGEST_LIFETIME) {
                throw new RejectedAnswerException('The provider answered a token worth more than a year, which is no lifetime of an access token.');
            }

            return new self(
                $document->string('access_token'),
                $expiresIn,
                $document->nullableString('refresh_token'),
                $document->nullableString('id_token'),
                $document->nullableString('scope'),
            );
        } catch (UnreadableDocumentException $exception) {
            throw new RejectedAnswerException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * This answer as the refresh of the tokens held before it (RFC 6749 §6): a provider may issue a new refresh token,
     * which then replaces the one held, and one that issues none leaves the one held as it was; a scope it does not say
     * is the scope originally granted.
     */
    public function asRefreshOf(self $previous): self
    {
        return new self(
            $this->accessToken,
            $this->expiresIn,
            $this->refreshToken ?? $previous->refreshToken,
            $this->idToken,
            $this->scope ?? $previous->scope,
        );
    }
}
