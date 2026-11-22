<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use function sprintf;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The releases of the repository this program is released from, read from the GitHub REST API without any
 * credential: the newest page of one hundred, of which a release counts only when it is published, neither a draft
 * nor a prerelease, and tagged `A.B.C`. Its files are downloaded from the address the release downloads of that
 * repository serve them at, and from no other.
 */
final readonly class GitHubReleases
{
    public const string REPOSITORY = 'beffroi-auth/beffroi';

    public const string LIST = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=100';

    public const string DOWNLOADS = 'https://github.com/' . self::REPOSITORY . '/releases/download/';

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * The newest release of the line of the version given and above it, and none when there is none.
     *
     * @throws SelfUpdateException when the releases cannot be read, or the release found names a file elsewhere
     */
    public function newestInTheLineOf(ReleaseVersion $running): ?Release
    {
        $newest = null;
        $version = $running;
        try {
            foreach (Document::decodeList($this->listed(), 'list of the releases') as $release) {
                $candidate = self::publishedVersion($release);
                if ($candidate !== null && $candidate->isInTheLineOf($running) && $candidate->isAbove($version)) {
                    [$newest, $version] = [$release, $candidate];
                }
            }

            return $newest === null ? null : new Release($version, self::assetsOf($newest, $version));
        } catch (UnreadableDocumentException $exception) {
            throw new SelfUpdateException(
                sprintf('GitHub answered a list of the releases of %s that is not readable: %s', self::REPOSITORY, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * @throws SelfUpdateException
     */
    private function listed(): string
    {
        try {
            $response = $this->http->request('GET', self::LIST, [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new SelfUpdateException(
                sprintf('The releases of %s cannot be fetched: %s', self::REPOSITORY, $exception->getMessage()),
                previous: $exception,
            );
        }
        if ($status === 404) {
            throw new SelfUpdateException(sprintf('GitHub shows no release of %s to a reader without credentials (404).', self::REPOSITORY));
        }
        if ($status !== 200) {
            throw new SelfUpdateException(sprintf('GitHub answered %d to the list of the releases of %s.', $status, self::REPOSITORY));
        }

        return $body;
    }

    /**
     * @throws UnreadableDocumentException
     */
    private static function publishedVersion(Document $release): ?ReleaseVersion
    {
        if ($release->nullableBool('draft') !== false || $release->nullableBool('prerelease') !== false) {
            return null;
        }
        $tag = $release->nullableString('tag_name');

        return $tag === null ? null : ReleaseVersion::fromTag($tag);
    }

    /**
     * The files of the release that are uploaded whole, by their name.
     *
     * @throws UnreadableDocumentException
     * @throws SelfUpdateException when a file is not where the release downloads of the repository serve it
     *
     * @return array<string, ReleaseAsset>
     */
    private static function assetsOf(Document $release, ReleaseVersion $version): array
    {
        $assets = [];
        foreach ($release->documents('assets') as $asset) {
            if ($asset->nullableString('state') !== 'uploaded') {
                continue;
            }
            $name = $asset->string('name');
            $url = $asset->string('browser_download_url');
            $expected = self::DOWNLOADS . $version->tag . '/' . $name;
            if ($url !== $expected) {
                throw new SelfUpdateException(sprintf('The release %s names its file %s at %s, and not at %s.', $version->tag, $name, $url, $expected));
            }
            $size = $asset->nullableInt('size');
            if ($size === null || $size < 1) {
                throw new UnreadableDocumentException(sprintf('The file %s of the release %s announces no size.', $name, $version->tag));
            }
            $assets[$name] = new ReleaseAsset($name, $url, $size);
        }

        return $assets;
    }
}
