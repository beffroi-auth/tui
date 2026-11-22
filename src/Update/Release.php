<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

use function sprintf;

/**
 * A published release and the files it carries.
 */
final readonly class Release
{
    /**
     * @param array<string, ReleaseAsset> $assets by their name
     */
    public function __construct(
        public ReleaseVersion $version,
        public array $assets,
    ) {
    }

    /**
     * @throws SelfUpdateException when the release carries no file of that name
     */
    public function asset(string $name): ReleaseAsset
    {
        return $this->assets[$name] ?? throw new SelfUpdateException(sprintf('The release %s carries no %s.', $this->version->tag, $name));
    }
}
