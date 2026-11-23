<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use Amp\ByteStream\StreamException;
use function Amp\ByteStream\buffer;
use function Amp\async;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use function basename;
use function copy;
use function dirname;
use function explode;
use function in_array;
use function is_executable;
use function is_file;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * The signature verified by `gpgv` of GnuPG, under a keyring this program carries and no key of the machine: its
 * own home, the keyring copied out of the archive, which gpgv cannot read inside one. A signature counts only when
 * gpgv ends well and reports it valid for the primary key pinned here, so that a keyring holding another key
 * vouches for nothing. Without gpgv, nothing is verified and nothing is replaced.
 */
final readonly class GpgvSignature implements SignatureVerifierInterface
{
    /**
     * The key the release workflow signs `SHA256SUMS` and the release tags with, the one `RELEASES_KEYRING` holds.
     */
    public const string RELEASES = '71E895E3E2F61952D19087F58B4BA35E2D6191D9';

    public const string RELEASES_KEYRING = 'release-signing-key.gpg';

    /**
     * @param string $keyring the OpenPGP keyring, binary, holding the key
     * @param string $fingerprint the primary key the signature must be valid for
     * @param string $path the directories gpgv is looked for in, as `PATH` lists them
     */
    public function __construct(
        private string $keyring,
        private string $fingerprint,
        private string $path,
    ) {
    }

    /**
     * @param array<array-key, mixed> $environment
     */
    public static function ofTheReleases(array $environment): self
    {
        $path = $environment['PATH'] ?? '';

        return new self(dirname(__DIR__, 2) . '/resources/' . self::RELEASES_KEYRING, self::RELEASES, is_string($path) ? $path : '');
    }

    public function verify(string $signedFile, string $signatureFile): void
    {
        $gpgv = $this->gpgv();
        $home = TemporaryDirectory::create();
        try {
            $keyring = $home->file('keyring.gpg');
            if (! copy($this->keyring, $keyring)) {
                throw new SelfUpdateException(sprintf('The keyring %s cannot be copied for gpgv.', $this->keyring));
            }
            [$status, $exit] = self::run([$gpgv, '--homedir', $home->path, '--status-fd', '1', '--keyring', $keyring, $signatureFile, $signedFile]);
        } finally {
            $home->remove();
        }
        if ($exit !== 0 || ! in_array($this->fingerprint, self::validFor($status), true)) {
            throw new SelfUpdateException(sprintf(
                'gpgv finds no good signature by the key %s in %s: nothing is replaced.',
                $this->fingerprint,
                basename($signatureFile),
            ));
        }
    }

    /**
     * @throws SelfUpdateException
     */
    private function gpgv(): string
    {
        foreach (explode(':', $this->path) as $directory) {
            $candidate = $directory . '/gpgv';
            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new SelfUpdateException('The signature of a release is verified by gpgv, which none of the directories of PATH holds: install GnuPG, then run the update again.');
    }

    /**
     * @param list<string> $command
     *
     * @throws SelfUpdateException
     *
     * @return array{string, int} what gpgv wrote to its status descriptor, and how it ended
     */
    private static function run(array $command): array
    {
        try {
            $process = Process::start($command, environment: ['LC_ALL' => 'C']);
            // read and dropped, so that gpgv never waits on a full pipe
            $errors = async(static fn (): string => buffer($process->getStderr()));
            $status = buffer($process->getStdout());
            $errors->await();

            return [$status, $process->join()];
        } catch (ProcessException|StreamException $exception) {
            throw new SelfUpdateException(sprintf('gpgv cannot be run: %s', $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * The primary keys gpgv reports a valid signature for, the tenth argument of each `VALIDSIG` line (GnuPG,
     * doc/DETAILS, "VALIDSIG").
     *
     * @return list<string>
     */
    private static function validFor(string $status): array
    {
        $keys = [];
        foreach (explode("\n", $status) as $line) {
            if (str_starts_with($line, '[GNUPG:] VALIDSIG ')) {
                $fields = explode(' ', $line);
                $keys[] = $fields[11] ?? '';
            }
        }

        return $keys;
    }
}
