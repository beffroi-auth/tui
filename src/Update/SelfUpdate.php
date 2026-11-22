<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function file_get_contents;
use function hash_equals;
use function hash_file;
use function sprintf;
use Throwable;

/**
 * The program replaced by the newest release of its line. The order is the guarantee: `SHA256SUMS` is downloaded
 * and its signature verified before the file of the program is downloaded, the file is held to its digest, and only
 * then does it take the place of the program. Every failure leaves the program as it was.
 */
final readonly class SelfUpdate
{
    public const string SUMS = 'SHA256SUMS';

    public const string SIGNATURE = 'SHA256SUMS.asc';

    public function __construct(
        private GitHubReleases $releases,
        private AssetDownload $downloads,
        private SignatureVerifierInterface $signature,
        private ProgramReplacement $replacement,
    ) {
    }

    /**
     * The release the program was replaced by, and none when no release of its line is newer.
     *
     * @throws SelfUpdateException
     */
    public function update(RunningProgram $program): ?ReleaseVersion
    {
        $path = $program->path();
        $asset = $program->asset();
        $release = $this->releases->newestInTheLineOf($program->release());
        if ($release === null) {
            return null;
        }
        $digest = $this->verifiedDigest($release, $asset);

        $staged = $this->replacement->stage($path);
        try {
            $this->downloads->to($release->asset($asset), $staged);
            $downloaded = hash_file('sha256', $staged);
            if ($downloaded === false || ! hash_equals($digest, $downloaded)) {
                throw new SelfUpdateException(sprintf(
                    'The %s the release %s served is not the one its signed %s names: nothing is replaced.',
                    $asset,
                    $release->version->tag,
                    self::SUMS,
                ));
            }
            $this->replacement->replace($path, $staged);
        } catch (Throwable $exception) {
            $this->replacement->discard($staged);

            throw $exception;
        }

        return $release->version;
    }

    /**
     * @throws SelfUpdateException
     */
    private function verifiedDigest(Release $release, string $asset): string
    {
        $work = TemporaryDirectory::create();
        try {
            $this->downloads->to($release->asset(self::SUMS), $work->file(self::SUMS));
            $this->downloads->to($release->asset(self::SIGNATURE), $work->file(self::SIGNATURE));
            $this->signature->verify($work->file(self::SUMS), $work->file(self::SIGNATURE));
            $sums = file_get_contents($work->file(self::SUMS));
            if ($sums === false) {
                throw new SelfUpdateException(sprintf('The %s of the release %s cannot be read.', self::SUMS, $release->version->tag));
            }

            return Sha256Sums::parse($sums)->digestOf($asset);
        } finally {
            $work->remove();
        }
    }
}
