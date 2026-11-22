<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function bin2hex;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * A directory of the system's temporary one that this user alone reads, for the files of one update, and removed
 * with them.
 */
final readonly class TemporaryDirectory
{
    private function __construct(
        public string $path,
    ) {
    }

    /**
     * @throws SelfUpdateException when the directory cannot be created
     */
    public static function create(): self
    {
        $path = sprintf('%s/beffroi-tui-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        if (! @mkdir($path, 0o700)) {
            throw new SelfUpdateException(sprintf('The temporary directory %s cannot be created.', $path));
        }

        return new self($path);
    }

    public function file(string $name): string
    {
        return $this->path . '/' . $name;
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && ! is_link($child)) {
                self::removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
