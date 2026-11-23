<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function array_key_exists;
use function explode;
use function hash;
use function hash_equals;
use function preg_match;
use function rtrim;
use function sprintf;

/**
 * The `SHA256SUMS` of a release as `sha256sum` writes it, one line per file: the digest in lowercase hexadecimal,
 * a space, a space or an asterisk, the name. A line of any other form, or a name given twice, refuses the whole
 * file, since it is what the signature vouches for.
 */
final readonly class Sha256Sums
{
    /**
     * The file the release workflow writes the tag of the release into, followed by a line feed, as it writes
     * `resources/release` of the PHAR, and lists with the others: what ties the signature of the sums to one release.
     */
    public const string RELEASE = 'release';

    /**
     * @param array<string, string> $digests by the name of the file
     */
    private function __construct(
        private array $digests,
    ) {
    }

    /**
     * @throws SelfUpdateException when a line is not one `sha256sum` writes, or a name is given twice
     */
    public static function parse(string $text): self
    {
        $digests = [];
        foreach (explode("\n", rtrim($text, "\n")) as $number => $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](\S.*)$/D', $line, $parts) !== 1) {
                throw new SelfUpdateException(sprintf('Line %d of SHA256SUMS is not a digest followed by a name.', $number + 1));
            }
            if (array_key_exists($parts[2], $digests)) {
                throw new SelfUpdateException(sprintf('SHA256SUMS gives the digest of %s twice.', $parts[2]));
            }
            $digests[$parts[2]] = $parts[1];
        }

        return new self($digests);
    }

    /**
     * Whether these are the sums of that release, and not those of another one served under its tag.
     */
    public function areOf(ReleaseVersion $version): bool
    {
        $named = $this->digests[self::RELEASE] ?? null;

        return $named !== null && hash_equals($named, hash('sha256', $version->tag . "\n"));
    }

    /**
     * @throws SelfUpdateException when the file gives no digest of that name
     */
    public function digestOf(string $name): string
    {
        return $this->digests[$name] ?? throw new SelfUpdateException(sprintf('SHA256SUMS gives no digest of %s.', $name));
    }
}
