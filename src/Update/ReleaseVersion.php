<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function preg_match;

/**
 * The version of a release, its tag `A.B.C` as the release workflow reads it; `A.B` is its line, within which a
 * release of a `0.x` version replaces another without breaking what it talks to.
 */
final readonly class ReleaseVersion
{
    private function __construct(
        public string $tag,
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    /**
     * The version a tag names, and none when the tag is not a release tag.
     */
    public static function fromTag(string $tag): ?self
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/D', $tag, $parts) !== 1) {
            return null;
        }

        return new self($tag, (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    public function isInTheLineOf(self $other): bool
    {
        return $this->major === $other->major && $this->minor === $other->minor;
    }

    public function isAbove(self $other): bool
    {
        return [$this->major, $this->minor, $this->patch] > [$other->major, $other->minor, $other->patch];
    }
}
