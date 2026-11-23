<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function dirname;
use function file_get_contents;
use function is_file;
use function preg_match;
use function trim;

/**
 * What the release workflow wrote into `resources/` before it compiled the PHAR, and the sources do not carry: the tag
 * of the release it built from, in `resources/release`, or the name of a build of any other ref, `<ref>-<commit>`, in
 * `resources/build`.
 */
final readonly class ThisBuild
{
    public static function version(): ?ReleaseVersion
    {
        return self::versionIn(self::resources() . '/release');
    }

    public static function versionIn(string $file): ?ReleaseVersion
    {
        $tag = self::read($file);

        return $tag === null ? null : ReleaseVersion::fromTag($tag);
    }

    /**
     * The name of a build of another ref than a release tag, and none for a release or the sources.
     */
    public static function build(): ?string
    {
        return self::buildIn(self::resources() . '/build');
    }

    public static function buildIn(string $file): ?string
    {
        $name = self::read($file);

        return $name !== null && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#D', $name) === 1 ? $name : null;
    }

    private static function resources(): string
    {
        return dirname(__DIR__, 2) . '/resources';
    }

    private static function read(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : trim($content);
    }
}
