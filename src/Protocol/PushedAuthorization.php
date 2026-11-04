<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use Beffroi\Tui\Json\UnreadableDocumentException;
use Beffroi\Tui\Provider\ProviderMetadata;
use function http_build_query;
use const PHP_QUERY_RFC3986;
use function str_contains;

/**
 * The authorization request pushed to the provider (RFC 9126 §2.1, FAPI 2.0 Security Profile §5.3.3.2, item 2),
 * authenticated as the client and proven by the key of this run, so that the code it ends in is bound to that
 * key (RFC 9449 §10.1); what the browser is sent to then names the client and the "request_uri" the provider
 * answered, and nothing else (RFC 9126 §4, FAPI 2.0 §5.3.3.2, item 6).
 */
final readonly class PushedAuthorization
{
    private const string ENDPOINT = 'pushed authorization request endpoint';

    public function __construct(
        private ProviderEndpoint $endpoint,
        private ProviderMetadata $provider,
    ) {
    }

    /**
     * The URL of the authorization endpoint to send the browser to.
     *
     * @throws ProtocolException when the provider refuses the request or answers what this program cannot read
     */
    public function push(AuthorizationRequest $request): string
    {
        $answer = $this->endpoint->post(self::ENDPOINT, $this->provider->pushedAuthorizationRequestEndpoint, $request->parameters(), true)->expect(201);

        try {
            $requestUri = $answer->string('request_uri');
        } catch (UnreadableDocumentException $exception) {
            throw new RejectedAnswerException($exception->getMessage(), previous: $exception);
        }
        $endpoint = $this->provider->authorizationEndpoint;

        return (
            $endpoint
            . (str_contains($endpoint, '?') ? '&' : '?')
            . http_build_query(
                [
                    'client_id' => $request->clientId,
                    'request_uri' => $requestUri,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            )
        );
    }
}
