<?php

declare(strict_types=1);

namespace Beffroi\Tui\Update;

/**
 * A file a release carries: where it is downloaded from, and the size the release announces for it in octets.
 */
final readonly class ReleaseAsset
{
    public function __construct(
        public string $name,
        public string $url,
        public int $size,
    ) {
    }
}
