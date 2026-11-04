<?php

declare(strict_types=1);

namespace Beffroi\Tui;

use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client every request of this program goes through: on the event loop of symfony/tui (Revolt), so a
 * request suspends the fiber that made it and freezes nothing of the screen; a redirection is never followed
 * (decision 0006), every endpoint being the one the metadata named; the certificate of the instance is verified,
 * under the certificate authorities of the system or under those of the file the profile names, and never not;
 * an answer is read up to a megabyte (`ResponseSizeLimit`); a name under `localhost.` is the loopback address
 * (`LocalhostNames`).
 */
final readonly class HttpClients
{
    public static function create(?string $certificateAuthority): HttpClientInterface
    {
        return new LocalhostNames(new AmpHttpClient([
            'max_redirects' => 0,
            'timeout' => 10,
            'max_duration' => 30,
            'cafile' => $certificateAuthority,
            'on_progress' => new ResponseSizeLimit(),
            'headers' => [
                'User-Agent' => 'beffroi-tui',
            ],
        ]));
    }
}
