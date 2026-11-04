<?php

declare(strict_types=1);

namespace Beffroi\Tui\Profile;

use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Json\UnreadableDocumentException;
use function chmod;
use function clearstatcache;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_string;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use function link;
use function mkdir;
use const PHP_OS_FAMILY;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function tempnam;
use function unlink;

/**
 * Where the profiles of this program are kept: one file per instance, named by the profile, in a directory
 * of the configuration home of the user that only that user may open.
 *
 * A profile holds the private key of a client and the token that manages its registration, so it is read
 * the way ssh reads a private key: a file that the group or anyone else may read or write is refused rather
 * than used, and the refusal says how to close it; so is the file of certificate authorities it names
 * (`CertificateAuthorityFile`). It is written the same way, created readable by its owner alone before a byte of
 * it is written, then linked under its name, which fails when the name is taken, and never written over: a
 * registration it replaced would be left at the provider with nobody holding its key.
 */
final readonly class ProfileStore
{
    private const int OWNER_ONLY_FILE = 0o600;

    private const int OWNER_ONLY_DIRECTORY = 0o700;

    /**
     * Group and other, any of read, write and execute.
     */
    private const int OPEN_TO_OTHERS = 0o077;

    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * The directory of the XDG Base Directory Specification, `$XDG_CONFIG_HOME/beffroi/tui`, and
     * `$HOME/.config/beffroi/tui` when the first is not set or is not absolute.
     *
     * @param array<array-key, mixed> $environment
     *
     * @throws ProfileException when the environment names neither
     */
    public static function inConfigurationHome(array $environment): self
    {
        $configurationHome = $environment['XDG_CONFIG_HOME'] ?? null;
        if (! is_string($configurationHome) || ! str_starts_with($configurationHome, '/')) {
            $home = $environment['HOME'] ?? null;
            if (! is_string($home) || $home === '') {
                throw new ProfileException('Neither XDG_CONFIG_HOME nor HOME is set, so there is nowhere to keep a profile.');
            }
            $configurationHome = rtrim($home, '/') . '/.config';
        }

        return new self(rtrim($configurationHome, '/') . '/beffroi/tui');
    }

    /**
     * @throws ProfileException when the name is not one a file can be named after here
     */
    public function path(string $name): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $name) !== 1) {
            throw new ProfileException(sprintf('"%s" is not the name of a profile: lowercase letters, digits, "-" and "_", 63 at most.', $name));
        }

        return sprintf('%s/%s.json', $this->directory, $name);
    }

    /**
     * @throws ProfileException when the name is not one a file can be named after here
     */
    public function exists(string $name): bool
    {
        return file_exists($this->path($name));
    }

    /**
     * @throws ProfileException when there is no such profile, when the group or others may open its file, or
     *                          when it cannot be read as a profile
     */
    public function load(string $name): Profile
    {
        $path = $this->path($name);
        if (! file_exists($path)) {
            throw new ProfileException(sprintf('There is no profile "%s" (%s): register this program with an instance first.', $name, $path));
        }
        self::assertOwnerOnly($path);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ProfileException(sprintf('The profile %s cannot be read.', $path));
        }

        try {
            $profile = Profile::fromDocument(Document::decode($contents, sprintf('profile %s', $path)));
        } catch (UnreadableDocumentException $exception) {
            throw new ProfileException(sprintf('The profile %s cannot be used: %s', $path, $exception->getMessage()), previous: $exception);
        }
        if ($profile->certificateAuthority !== null) {
            CertificateAuthorityFile::at($profile->certificateAuthority);
        }

        return $profile;
    }

    /**
     * @throws ProfileException when the profile exists already or cannot be written
     */
    public function save(string $name, Profile $profile): string
    {
        $path = $this->path($name);
        if (file_exists($path)) {
            throw new ProfileException(sprintf(
                'The profile "%s" exists already (%s): its registration would be left with nobody holding its key. Name another profile, or delete the client and this file first.',
                $name,
                $path,
            ));
        }
        if (! is_dir($this->directory) && ! @mkdir($this->directory, self::OWNER_ONLY_DIRECTORY, true) && ! is_dir($this->directory)) {
            throw new ProfileException(sprintf('The directory %s cannot be created.', $this->directory));
        }
        @chmod($this->directory, self::OWNER_ONLY_DIRECTORY);

        // tempnam() creates the file readable and writable by its owner alone, before anything is written to it
        $temporary = tempnam($this->directory, '.profile-');
        if ($temporary === false) {
            throw new ProfileException(sprintf('No file can be created in %s.', $this->directory));
        }
        $written = file_put_contents($temporary, json_encode($profile->toDocument(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        // link() makes the name only if nothing holds it yet, where rename() would replace what does
        $linked = $written !== false && chmod($temporary, self::OWNER_ONLY_FILE) && @link($temporary, $path);
        @unlink($temporary);
        if (! $linked) {
            throw new ProfileException(sprintf(
                file_exists($path) ? 'The profile %s was written by something else meanwhile.' : 'The profile %s cannot be written.',
                $path,
            ));
        }

        return $path;
    }

    /**
     * POSIX permissions only: a file system that has none, as Windows has none of these bits, refuses nothing.
     *
     * @throws ProfileException when the group or others may read, write or run the file
     */
    private static function assertOwnerOnly(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & self::OPEN_TO_OTHERS) !== 0) {
            throw new ProfileException(sprintf(
                'The profile %s holds the private key of a client and others may open it: it is not read until "chmod 600 %s" closes it.',
                $path,
                $path,
            ));
        }
    }
}
