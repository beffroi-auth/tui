<?php

declare(strict_types=1);

namespace Beffroi\Tui;

use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP clients of this program, on the event loop of symfony/tui (Revolt), so a request suspends the fiber that
 * made it and freezes nothing of the screen. Neither follows a redirection on its own (decision 0006), and both
 * verify the certificate they are answered under, never not.
 */
final readonly class HttpClients
{
    /**
     * The client every request goes through but the download of the files of a release: every endpoint is the one
     * the metadata named, so a redirection is not followed; the certificate of the instance is verified under the
     * certificate authorities of the system or under those of the file the profile names; an answer is read up to
     * a megabyte (`ResponseSizeLimit`); a name under `localhost.` is the loopback address (`LocalhostNames`).
     */
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

    /**
     * The client the files of a release are downloaded with, under the certificate authorities of the system: a
     * file is tens of megabytes, so each request names the size it reads up to and the whole of one may take ten
     * minutes. It follows no redirection either; `AssetDownload` follows the one a download is answered with.
     */
    public static function releaseAssets(): HttpClientInterface
    {
        return new AmpHttpClient([
            'max_redirects' => 0,
            'timeout' => 10,
            'max_duration' => 600,
            'headers' => [
                'User-Agent' => 'beffroi-tui',
            ],
        ]);
    }
}
