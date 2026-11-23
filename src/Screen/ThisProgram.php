<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Text\TerminalText;
use Beffroi\Tui\Update\GitHubReleases;
use Beffroi\Tui\Update\ReleaseVersion;
use Beffroi\Tui\Update\SelfUpdateException;
use function sprintf;

/**
 * The build of this program the screen names, and, for a release, whether a newer release of its line is out: the
 * one `self-update` would install, looked for once per run.
 */
final class ThisProgram
{
    private bool $lookedFor = false;

    private ?ReleaseVersion $newer = null;

    private ?string $failure = null;

    /**
     * @param ?ReleaseVersion $release the release this build was compiled from
     * @param ?string         $build   the name of a build of another ref, when it is not a release
     */
    public function __construct(
        private readonly ?ReleaseVersion $release,
        private readonly ?string $build,
        private readonly GitHubReleases $releases,
    ) {
    }

    /**
     * What `--version` prints, and none for a run from the sources.
     */
    public function name(): ?string
    {
        return $this->release->tag ?? $this->build;
    }

    /**
     * Once, and for a release alone: what the releases answer, or why they could not be read, is kept for the run.
     */
    public function lookForANewerRelease(): void
    {
        if ($this->release === null || $this->lookedFor) {
            return;
        }
        try {
            $this->newer = $this->releases->newestInTheLineOf($this->release)?->version;
        } catch (SelfUpdateException $exception) {
            $this->failure = $exception->getMessage();
        }
        $this->lookedFor = true;
    }

    /**
     * @return array{string, array<string, string>} the string of the catalogue that names this build, and its parameters
     */
    public function version(): array
    {
        if ($this->release !== null) {
            return ['program.release', ['version' => $this->release->tag]];
        }
        if ($this->build !== null) {
            return ['program.build', ['build' => $this->build]];
        }

        return ['program.sources', []];
    }

    /**
     * @return ?array{string, array<string, string>} the string of the catalogue that says where the look for a newer
     *                                               release stands, and its parameters; none for a build that is not
     *                                               a release, which nothing replaces
     */
    public function update(): ?array
    {
        return match (true) {
            $this->release === null => null,
            ! $this->lookedFor => ['program.looking', []],
            $this->newer !== null => ['program.newer', ['version' => $this->newer->tag]],
            $this->failure !== null => ['program.unchecked', ['reason' => TerminalText::line($this->failure)->value]],
            default => ['program.newest', ['line' => sprintf('%d.%d.x', $this->release->major, $this->release->minor)]],
        };
    }
}
