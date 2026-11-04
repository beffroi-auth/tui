<?php

declare(strict_types=1);

namespace Beffroi\Tui\Api;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Protocol\DpopProofs;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\ProviderAnswer;
use Beffroi\Tui\Protocol\UnreachableEndpointException;
use Beffroi\Tui\SignIn\HeldTokens;
use function http_build_query;
use function implode;
use const PHP_QUERY_RFC3986;
use function rtrim;
use SensitiveParameter;
use function sprintf;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The administration API of the instance (decision 0038), at the identifier the profile names, read with the tokens of
 * the administrator's session. Every request carries the access token under the DPoP scheme, and a proof of the key it
 * is bound to made for that request and that token (RFC 9449 §7.1, §4.2), with the last nonce the API handed out
 * (§9).
 *
 * The API that asks for a nonce is sent the request once more, its proof carrying it (§9); the API that refuses the
 * access token as no longer worth anything (RFC 6750 §3.1, "invalid_token") is sent it once more with a token the
 * session refreshed since, when it holds a refresh token. What the API answers is read as every answer of the instance
 * is: through the client of `HttpClients`, which follows no redirection (decision 0006) and reads an answer up to a
 * megabyte.
 */
final readonly class AdministrationApi
{
    private const string NAME = 'administration API';

    public function __construct(
        private HttpClientInterface $http,
        private string $identifier,
        private DpopProofs $proofs,
        private HeldTokens $tokens,
    ) {
    }

    /**
     * @param string                $path  the path of the resource under the API, its segments already encoded
     * @param array<string, string> $query
     *
     * @throws ProtocolException when the API refuses the request, answers what this program does not read, or answers nothing
     */
    public function read(string $path, array $query = []): Document
    {
        $url = rtrim($this->identifier, '/') . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $token = $this->tokens->accessToken();
        $answer = $this->send($url, $token);
        if ($answer->status === 401 && $answer->error() === 'invalid_token' && $this->tokens->replace($token)) {
            $answer = $this->send($url, $this->tokens->accessToken());
        }

        return $answer->expect(200);
    }

    /**
     * The request, and the same request once more when the API asks for a nonce in its proof (RFC 9449 §9).
     *
     * @throws UnreachableEndpointException
     */
    private function send(string $url, #[SensitiveParameter] string $token): ProviderAnswer
    {
        $answer = $this->once($url, $token);
        if ($answer->status === 401 && $answer->error() === 'use_dpop_nonce') {
            return $this->once($url, $token);
        }

        return $answer;
    }

    /**
     * @throws UnreachableEndpointException
     */
    private function once(string $url, #[SensitiveParameter] string $token): ProviderAnswer
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'DPoP ' . $token,
                    'DPoP' => $this->proofs->proof('GET', $url, $token),
                ],
            ]);
            $answer = new ProviderAnswer(
                self::NAME,
                $response->getStatusCode(),
                $response->getContent(false),
                implode(', ', $response->getHeaders(false)['www-authenticate'] ?? []),
            );
            $this->proofs->remember($url, $response);
        } catch (ExceptionInterface $exception) {
            throw new UnreachableEndpointException(sprintf('The %s cannot be reached: %s', self::NAME, $exception->getMessage()), previous: $exception);
        }

        return $answer;
    }
}
