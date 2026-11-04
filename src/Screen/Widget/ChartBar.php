<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A count and what it counts, a failure being drawn in the colour of errors, its label saying what failed.
 */
final readonly class ChartBar
{
    public function __construct(
        public string $label,
        public int $count,
        public bool $failure = false,
    ) {
    }
}
