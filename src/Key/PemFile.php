<?php

declare(strict_types=1);

namespace Beffroi\Tui\Key;

use function clearstatcache;
use function file_get_contents;
use function fileperms;
use function is_file;
use function is_readable;
use const PHP_OS_FAMILY;
use function realpath;
use SensitiveParameter;
use function strlen;

/**
 * A file in PEM the administrator names, read on their machine for the administration API: an S/MIME certificate
 * followed by its chain, or the private key it certifies. Whoever may read the private key signs the mail of the
 * tenant, so a key the group or others may open is refused, as a profile they may open is.
 */
final readonly class PemFile
{
    /**
     * Bytes, far above a key and a chain of a few certificates.
     */
    public const int LIMIT = 65_536;

    /**
     * Group and other, read, write and run.
     */
    private const int OPEN_TO_OTHERS = 0o077;

    private function __construct(
        #[SensitiveParameter]
        public string $contents,
    ) {
    }

    /**
     * @throws PemFileException when it is not a file that can be read, or it holds more than LIMIT bytes
     */
    public static function certificates(string $path): self
    {
        return new self(self::read(self::located($path), $path));
    }

    /**
     * POSIX permissions only: a file system that has none, as Windows has none of these bits, refuses nothing.
     *
     * @throws PemFileException when it is not a file that can be read, it holds more than LIMIT bytes, or the group or
     *                          others may open it
     */
    public static function privateKey(string $path): self
    {
        $file = self::located($path);
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $file);
            $permissions = fileperms($file);
            if ($permissions === false || ($permissions & self::OPEN_TO_OTHERS) !== 0) {
                throw PemFileException::openToOthers($file);
            }
        }

        return new self(self::read($file, $path));
    }

    /**
     * @throws PemFileException
     */
    private static function located(string $path): string
    {
        $file = realpath($path);
        if ($file === false || ! is_file($file) || ! is_readable($file)) {
            throw PemFileException::notAFile($path);
        }

        return $file;
    }

    /**
     * @throws PemFileException
     */
    private static function read(string $file, string $path): string
    {
        $contents = @file_get_contents($file, false, null, 0, self::LIMIT + 1);
        if ($contents === false) {
            throw PemFileException::notAFile($path);
        }
        if (strlen($contents) > self::LIMIT) {
            throw PemFileException::tooLarge($path);
        }

        return $contents;
    }
}
