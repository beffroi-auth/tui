<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function dirname;
use function file_get_contents;
use function is_file;
use function trim;

/**
 * The release this build of the program was compiled from: the tag the release workflow writes into
 * `resources/release` before it compiles the PHAR, which no other build writes and the sources do not carry.
 */
final readonly class ThisBuild
{
    public static function version(): ?ReleaseVersion
    {
        return self::versionIn(dirname(__DIR__, 2) . '/resources/release');
    }

    public static function versionIn(string $file): ?ReleaseVersion
    {
        if (! is_file($file)) {
            return null;
        }
        $tag = file_get_contents($file);

        return $tag === false ? null : ReleaseVersion::fromTag(trim($tag));
    }
}
