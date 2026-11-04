<?php

declare(strict_types=1);

namespace Beffroi\Tui;

use function is_array;
use function is_string;
use function parse_url;
use const PHP_URL_HOST;
use function str_ends_with;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A name under `localhost.` is the loopback address and is never asked of a name server (RFC 6761 §6.3), which
 * is how a browser and curl read `op.localhost`; the resolver of Amp asks the name server like for any other
 * name, and finds nothing. This client answers those names with the loopback address before a request is made,
 * the way the RFC says a library should, and leaves every other name to the resolver.
 */
final class LocalhostNames implements HttpClientInterface
{
    use DecoratorTrait;

    private const string LOOPBACK = '127.0.0.1';

    /**
     * @param array<array-key, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            $resolve = $options['resolve'] ?? [];
            $options['resolve'] = [$host => self::LOOPBACK] + (is_array($resolve) ? $resolve : []);
        }

        return $this->client->request($method, $url, $options);
    }
}
