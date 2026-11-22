<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function basename;
use function bin2hex;
use function chmod;
use function dirname;
use function fclose;
use function fileperms;
use function fopen;
use function is_file;
use function is_writable;
use function random_bytes;
use function rename;
use function sprintf;
use function unlink;

/**
 * The file of the program replaced by another in one rename: the new one is written beside it, in the same
 * directory and so on the same file system, takes its mode, and takes its place once it was verified. The program
 * keeps running from the file it opened. A directory this user cannot write to is refused; nothing here asks for
 * more privileges.
 */
final readonly class ProgramReplacement
{
    /**
     * A new empty file beside the program, that this user alone reads.
     *
     * @throws SelfUpdateException when the directory of the program is not writable by this user
     */
    public function stage(string $program): string
    {
        $directory = dirname($program);
        if (! is_writable($directory)) {
            throw new SelfUpdateException(sprintf(
                'The directory %s is not writable by this user, and the update asks for no more privileges: nothing is replaced.',
                $directory,
            ));
        }
        $staged = sprintf('%s/.%s.%s', $directory, basename($program), bin2hex(random_bytes(8)));
        $file = @fopen($staged, 'xb');
        if ($file === false) {
            throw new SelfUpdateException(sprintf('The file %s cannot be created.', $staged));
        }
        fclose($file);
        chmod($staged, 0o600);

        return $staged;
    }

    /**
     * @throws SelfUpdateException when the staged file cannot take the mode or the place of the program
     */
    public function replace(string $program, string $staged): void
    {
        $mode = fileperms($program);
        if ($mode === false || ! @chmod($staged, $mode & 0o777) || ! @rename($staged, $program)) {
            throw new SelfUpdateException(sprintf('%s cannot take the place of %s.', $staged, $program));
        }
    }

    public function discard(string $staged): void
    {
        if (is_file($staged)) {
            @unlink($staged);
        }
    }
}
