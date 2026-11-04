<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A figure and what it counts.
 */
final readonly class Tile
{
    public function __construct(
        public string $figure,
        public string $label,
    ) {
    }
}
