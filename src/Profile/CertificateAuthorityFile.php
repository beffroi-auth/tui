<?php

declare(strict_types=1);

namespace Beffroi\Tui\Profile;

use function clearstatcache;
use function fileperms;
use function is_file;
use function is_readable;
use const PHP_OS_FAMILY;
use function realpath;
use function sprintf;

/**
 * The file of certificate authorities a profile trusts its instance under in place of those of the system, by the
 * absolute path that a run from another directory reads the same. Whoever may write it may add an authority of
 * their own and answer for the instance, so a file the group or others may write is refused, as a profile they may
 * read is.
 */
final readonly class CertificateAuthorityFile
{
    /**
     * Group and other, write.
     */
    private const int WRITABLE_BY_OTHERS = 0o022;

    /**
     * @throws ProfileException when it is not a file that can be read, or others may write it
     */
    public static function at(string $file): string
    {
        $path = realpath($file);
        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new ProfileException(sprintf('"%s" is not a file of certificate authorities that can be read.', $file));
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return $path;
        }
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & self::WRITABLE_BY_OTHERS) !== 0) {
            throw new ProfileException(sprintf(
                'Others may write the certificate authorities of %s, which an instance is trusted under: they are not trusted until "chmod go-w %s" closes it.',
                $path,
                $path,
            ));
        }

        return $path;
    }
}
