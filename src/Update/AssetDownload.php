<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use Beffroi\Tui\ResponseSizeLimit;
use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function sprintf;
use function str_starts_with;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A file of a release written to disk as it arrives, read up to the size the release announces for it. GitHub
 * answers the download of a file with a redirection to where it stores it: that one redirection is followed, to an
 * HTTPS address, and no other. Where the file is served from does not vouch for it; the signed digest does.
 */
final readonly class AssetDownload
{
    private const array REDIRECTIONS = [301, 302, 303, 307, 308];

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * @throws SelfUpdateException when the file cannot be downloaded whole, or is not served one redirection away at most
     */
    public function to(ReleaseAsset $asset, string $path): void
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            throw new SelfUpdateException(sprintf('The file %s cannot be written to %s.', $asset->name, $path));
        }

        try {
            $response = $this->request($asset, $asset->url);
            if (in_array($response->getStatusCode(), self::REDIRECTIONS, true)) {
                $location = self::location($asset, $response);
                $response->cancel();
                $response = $this->request($asset, $location);
            }
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new SelfUpdateException(sprintf('The download of %s was answered %d.', $asset->name, $status));
            }
            foreach ($this->http->stream($response) as $chunk) {
                if (fwrite($file, $chunk->getContent()) === false) {
                    throw new SelfUpdateException(sprintf('The file %s cannot be written to %s.', $asset->name, $path));
                }
            }
        } catch (ExceptionInterface $exception) {
            throw new SelfUpdateException(sprintf('The file %s cannot be downloaded: %s', $asset->name, $exception->getMessage()), previous: $exception);
        } finally {
            fclose($file);
        }
    }

    /**
     * @throws ExceptionInterface
     */
    private function request(ReleaseAsset $asset, string $url): ResponseInterface
    {
        return $this->http->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/octet-stream',
            ],
            'on_progress' => new ResponseSizeLimit($asset->size),
        ]);
    }

    /**
     * @throws ExceptionInterface
     * @throws SelfUpdateException when the redirection names no HTTPS address
     */
    private static function location(ReleaseAsset $asset, ResponseInterface $response): string
    {
        $location = $response->getHeaders(false)['location'][0] ?? '';
        if (! str_starts_with($location, 'https://')) {
            throw new SelfUpdateException(sprintf('The download of %s is redirected to "%s", which is not an HTTPS address.', $asset->name, $location));
        }

        return $location;
    }
}
