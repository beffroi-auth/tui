<?php

declare(strict_types=1);

namespace Beffroi\Tui\Key;

use RuntimeException;
use function sprintf;

/**
 * A file named for a key or a certificate that is not read: `$reason` names why, as the catalogue of this program
 * names the refusal (`pem_file.<reason>`).
 */
final class PemFileException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAFile(string $path): self
    {
        return new self('not_a_file', $path, sprintf('"%s" is not a file that can be read.', $path));
    }

    public static function tooLarge(string $path): self
    {
        return new self('too_large', $path, sprintf('"%s" holds more than %d bytes.', $path, PemFile::LIMIT));
    }

    public static function openToOthers(string $path): self
    {
        return new self('open_to_others', $path, sprintf('Others may open the private key %s: it is not read until "chmod 600 %s" closes it.', $path, $path));
    }
}
