<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\TimeoutCancellation;
use function Amp\async;
use function Amp\Socket\listen;
use Closure;
use function count;
use function explode;
use function in_array;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function strlen;
use function strstr;
use function substr;
use Throwable;

/**
 * Where the browser brings the authorization response back to this program (RFC 8252 §7.3): the loopback
 * interface named by its IP literal and never by a name (§8.3), bound on that interface alone, at the port the
 * client registered, and answering the one redirection it waits for.
 *
 * The port is one anybody on this machine can see and reach, a local process as much as a page the browser shows,
 * so what reaches it is the response only when the caller says so, which it does by the state of the request
 * (RFC 6749 §10.12): a request without it is answered 400 and the listener goes on waiting, rather than end the
 * sign-in on whatever came first. Anything else is answered and forgotten too: another path or another method is a
 * 404, a request that does not finish its head within a few seconds or within a few kilobytes is dropped, each
 * connection is served by itself, so that one a browser opens ahead and never uses does not hold up the one that
 * carries the response, and beyond a few dozen at a time a connection is closed unanswered. The page the browser
 * is answered says nothing of the response, refers nothing, loads nothing and may be framed by nobody, so the code
 * in its URL goes nowhere else.
 */
final readonly class LoopbackListener
{
    private const int MAXIMUM_HEAD = 8192;

    private const float READ_TIMEOUT = 5.0;

    private const int MAXIMUM_CONNECTIONS = 32;

    /**
     * @var list<string>
     */
    private const array LOOPBACK_LITERALS = ['127.0.0.1', '[::1]'];

    private function __construct(
        private ServerSocket $server,
        private string $path,
        private string $page,
    ) {
    }

    /**
     * Binds the address of the redirect URI; port 0 asks the system for one, which `port()` then reads.
     *
     * @param string $page the HTML the browser is answered once it brought the response back
     *
     * @throws CannotListenException when the URI is not one of the loopback interface or its port is taken
     */
    public static function on(string $redirectUri, string $page): self
    {
        $parts = parse_url($redirectUri);
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : null;
        if (($parts['scheme'] ?? null) !== 'http' || ! in_array($host, self::LOOPBACK_LITERALS, true) || $port === null) {
            throw new CannotListenException(sprintf(
                '"%s" is not an address of the loopback interface named by its IP literal, with a port (RFC 8252 §7.3, §8.3).',
                $redirectUri,
            ));
        }

        try {
            $server = listen(sprintf('%s:%d', $host, $port));
        } catch (SocketException $exception) {
            throw new CannotListenException(
                sprintf('Nothing can listen on %s:%d, which this client registered: %s', $host, $port, $exception->getMessage()),
                previous: $exception,
            );
        }

        return new self($server, is_string($parts['path'] ?? null) ? $parts['path'] : '/', $page);
    }

    public function port(): int
    {
        $address = $this->server->getAddress();

        return $address instanceof InternetAddress ? $address->getPort() : 0;
    }

    /**
     * The query of the first request that reaches the registered path and is the response, then the listener is
     * closed.
     *
     * @param Closure(string): bool $isTheResponse whether a query is the response awaited
     *
     * @throws CancelledException when the cancellation is cancelled first
     * @throws CannotListenException when the listener stops accepting connections
     */
    public function awaitRedirection(Closure $isTheResponse, ?Cancellation $cancellation = null): string
    {
        /** @var DeferredFuture<string> $redirection */
        $redirection = new DeferredFuture();
        $open = 0;
        async(function () use ($redirection, $isTheResponse, &$open): void {
            try {
                while (! $redirection->isComplete() && ($socket = $this->server->accept()) !== null) {
                    if ($open >= self::MAXIMUM_CONNECTIONS) {
                        $socket->close();

                        continue;
                    }
                    ++$open;
                    async(function () use ($socket, $redirection, $isTheResponse, &$open): void {
                        try {
                            $this->serve($socket, $redirection, $isTheResponse);
                        } finally {
                            --$open;
                        }
                    });
                }
            } catch (Throwable $exception) {
                if (! $redirection->isComplete()) {
                    $redirection->error(
                        new CannotListenException(sprintf('The loopback interface stopped answering: %s', $exception->getMessage()), previous: $exception),
                    );
                }
            }
        });

        try {
            return $redirection->getFuture()->await($cancellation);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (! $this->server->isClosed()) {
            $this->server->close();
        }
    }

    /**
     * @param DeferredFuture<string> $redirection
     * @param Closure(string): bool  $isTheResponse
     */
    private function serve(Socket $socket, DeferredFuture $redirection, Closure $isTheResponse): void
    {
        try {
            $head = self::headOf($socket);
            [$method, $path, $query] = $head === null ? ['', '', ''] : self::requestOf($head);
            if ($method !== 'GET' || $path !== $this->path) {
                $socket->write(self::response('404 Not Found', ''));

                return;
            }
            if ($redirection->isComplete() || ! $isTheResponse($query)) {
                $socket->write(self::response('400 Bad Request', ''));

                return;
            }
            $socket->write(self::response('200 OK', $this->page));
            $redirection->complete($query);
        } catch (Throwable) {
            // a connection that broke off is one that brought nothing
        } finally {
            $socket->close();
        }
    }

    /**
     * The request line and the headers, or null when they do not end within the bounds.
     */
    private static function headOf(Socket $socket): ?string
    {
        $head = '';
        $cancellation = new TimeoutCancellation(self::READ_TIMEOUT);
        while (! str_contains($head, "\r\n\r\n")) {
            $chunk = $socket->read($cancellation);
            if ($chunk === null || strlen($head .= $chunk) > self::MAXIMUM_HEAD) {
                return null;
            }
        }

        return $head;
    }

    /**
     * The method, the path and the query of the request line (RFC 9112 §3), none of them when it is not one.
     *
     * @return array{string, string, string}
     */
    private static function requestOf(string $head): array
    {
        $line = strstr($head, "\r\n", true);
        $parts = explode(' ', $line === false ? $head : $line);
        if (count($parts) !== 3) {
            return ['', '', ''];
        }
        [$method, $target] = $parts;
        $query = strstr($target, '?');

        return (
            $query === false
                ? [$method, $target, '']
                : [$method, substr($target, 0, strlen($target) - strlen($query)), substr($query, 1)]
        );
    }

    private static function response(string $status, string $body): string
    {
        return sprintf(
            "HTTP/1.1 %s\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: %d\r\nCache-Control: no-store\r\nReferrer-Policy: no-referrer\r\nContent-Security-Policy: default-src 'none'; frame-ancestors 'none'\r\nX-Content-Type-Options: nosniff\r\nConnection: close\r\n\r\n%s",
            $status,
            strlen($body),
            $body,
        );
    }
}
