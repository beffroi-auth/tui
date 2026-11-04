<?php

declare(strict_types=1);

namespace Beffroi\Tui\Protocol;

use function sprintf;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A request to an endpoint of the provider that authenticates its clients: the form a request carries, the
 * client authentication drawn for it, and, for an endpoint whose answer is bound to the key of this run, a
 * DPoP proof (RFC 9449 §5 at the token endpoint, §10.1 at the pushed authorization request endpoint).
 *
 * A provider that wants a nonce in the proof answers "use_dpop_nonce" with the nonce to use (§8), and the
 * request is sent once more, with a proof and an assertion drawn again: both are worth one request.
 */
final readonly class ProviderEndpoint
{
    public function __construct(
        private HttpClientInterface $http,
        private ClientAuthenticationInterface $authentication,
        private DpopProofs $proofs,
    ) {
    }

    /**
     * @param string                $name       what the endpoint is, for a refusal to name it
     * @param array<string, string> $parameters
     *
     * @throws UnreachableEndpointException when the endpoint answers nothing
     */
    public function post(string $name, string $endpoint, array $parameters, bool $bound): ProviderAnswer
    {
        $answer = $this->send($name, $endpoint, $parameters, $bound);
        if ($bound && $answer->status === 400 && $answer->error() === 'use_dpop_nonce') {
            return $this->send($name, $endpoint, $parameters, $bound);
        }

        return $answer;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @throws UnreachableEndpointException
     */
    private function send(string $name, string $endpoint, array $parameters, bool $bound): ProviderAnswer
    {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($bound) {
            $headers['DPoP'] = $this->proofs->proof('POST', $endpoint);
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => $headers,
                'body' => [...$parameters, ...$this->authentication->parameters()],
            ]);
            $answer = new ProviderAnswer($name, $response->getStatusCode(), $response->getContent(false));
            $this->proofs->remember($endpoint, $response);
        } catch (ExceptionInterface $exception) {
            throw new UnreachableEndpointException(sprintf('The %s cannot be reached: %s', $name, $exception->getMessage()), previous: $exception);
        }

        return $answer;
    }
}
