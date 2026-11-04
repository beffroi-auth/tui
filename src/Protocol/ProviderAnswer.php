<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use function sprintf;

/**
 * What an endpoint of the instance answered: its status and its body, read as the JSON object every endpoint this
 * program calls answers with, a success as a refusal (RFC 6749 §5.1, §5.2), and the challenges of a protected
 * resource, which says why it refused a token in its `WWW-Authenticate` header rather than in its body (RFC 6750 §3).
 */
final readonly class ProviderAnswer
{
    /**
     * @param string $challenges the values of the `WWW-Authenticate` header, joined by commas, empty when it carries none
     */
    public function __construct(
        public string $endpoint,
        public int $status,
        private string $body,
        private string $challenges = '',
    ) {
    }

    /**
     * The document of an answer with the status a success has at this endpoint, and the refusal it carries
     * otherwise.
     *
     * @throws ProviderRefusalException when the status is another one
     * @throws RejectedAnswerException when the body of a success is not a JSON object
     */
    public function expect(int $status): Document
    {
        if ($this->status !== $status) {
            throw $this->refusal();
        }

        try {
            return Document::decode($this->body, sprintf('answer of the %s', $this->endpoint));
        } catch (UnreadableDocumentException $exception) {
            throw new RejectedAnswerException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The "error" of a refusal, null when neither the body nor a challenge says one.
     */
    public function error(): ?string
    {
        return $this->refusal()->error;
    }

    /**
     * What the body says first, then what a challenge says: a body that is not the JSON of RFC 6749 §5.2 says nothing,
     * which is what a resource answers to a request that carried no token at all (RFC 6750 §3.1).
     */
    public function refusal(): ProviderRefusalException
    {
        $error = AuthenticationChallenge::parameter($this->challenges, 'error');
        $description = AuthenticationChallenge::parameter($this->challenges, 'error_description');

        try {
            $document = Document::decode($this->body, 'refusal');
            $error = $document->nullableString('error') ?? $error;
            $description = $document->nullableString('error_description') ?? $description;
        } catch (UnreadableDocumentException) {
            // the challenge says what there is to say
        }

        return new ProviderRefusalException($this->endpoint, $this->status, $error, $description);
    }
}
