<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function extension_loaded;
use Phar;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use function php_uname;
use function sprintf;

/**
 * The file this program runs from and what a release carries in its place: `beffroi-tui.phar` when a PHP runs the
 * archive, the executable of the platform when the "micro" SAPI of static-php-cli runs it, the archive being then
 * the executable itself.
 */
final readonly class RunningProgram
{
    public const string PHAR = 'beffroi-tui.phar';

    /**
     * The executables the release workflow builds, by the family of the system and the machine PHP reports.
     */
    private const array EXECUTABLES = [
        'Linux x86_64' => 'beffroi-tui-linux-x86_64',
        'Linux aarch64' => 'beffroi-tui-linux-aarch64',
        'Darwin arm64' => 'beffroi-tui-macos-aarch64',
    ];

    /**
     * @param string $archive the PHAR that runs, empty when the program runs from its sources
     */
    public function __construct(
        public string $sapi,
        public string $archive,
        public string $system,
        public string $machine,
        public ?ReleaseVersion $version,
    ) {
    }

    public static function current(): self
    {
        return new self(
            PHP_SAPI,
            extension_loaded('phar') ? Phar::running(false) : '',
            PHP_OS_FAMILY,
            php_uname('m'),
            ThisBuild::version(),
        );
    }

    /**
     * @throws SelfUpdateException when the program runs from its sources
     */
    public function path(): string
    {
        if ($this->archive === '') {
            throw new SelfUpdateException('This program runs from its sources, as Composer installs it: Composer updates it.');
        }

        return $this->archive;
    }

    /**
     * @throws SelfUpdateException when this build was not compiled from a release
     */
    public function release(): ReleaseVersion
    {
        return (
            $this->version ?? throw new SelfUpdateException('This build of the program was not compiled from a release, and only a release replaces itself.')
        );
    }

    /**
     * @throws SelfUpdateException when no release carries an executable for this platform
     */
    public function asset(): string
    {
        if ($this->sapi !== 'micro') {
            return self::PHAR;
        }

        return (
            self::EXECUTABLES[sprintf('%s %s', $this->system, $this->machine)] ?? throw new SelfUpdateException(sprintf(
                'No release carries an executable for %s on %s.',
                $this->system,
                $this->machine,
            ))
        );
    }
}
