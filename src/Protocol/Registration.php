<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use SensitiveParameter;
use function sprintf;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * This program registers itself with a tenant (RFC 7591 §3.1), the one way a client reaches a deployment of
 * the provider: with the initial access token the tenant issued for it (`beffroi:client:token:issue`), presented
 * as a bearer token (§3), or with none when the tenant registers anyone (decision 0009).
 */
final readonly class Registration
{
    private const string ENDPOINT = 'registration endpoint';

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * @throws ProtocolException when the provider refuses or registers something else than what was asked
     */
    public function register(string $endpoint, ClientRegistrationRequest $request, #[SensitiveParameter] ?string $initialAccessToken): RegisteredClient
    {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($initialAccessToken !== null) {
            $headers['Authorization'] = sprintf('Bearer %s', $initialAccessToken);
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => $headers,
                'json' => $request->metadata(),
            ]);
            $answer = new ProviderAnswer(self::ENDPOINT, $response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface $exception) {
            throw new UnreachableEndpointException(sprintf('The registration endpoint cannot be reached: %s', $exception->getMessage()), previous: $exception);
        }

        return RegisteredClient::fromDocument($answer->expect(201), $request);
    }
}
