<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A column of a table, as wide as what it holds when the room allows, never narrower than its minimum, and dropped when
 * the table is too narrow for it, the column of the lowest priority first.
 */
final readonly class TableColumn
{
    /**
     * @param string $label    the heading of the column
     * @param int    $minimum  the columns under which what it holds no longer reads
     * @param int    $priority the higher, the later it is dropped when the table is narrow
     * @param bool   $right    whether it is aligned on the right, as numbers are
     * @param bool   $middle   whether a value too wide is cut in its middle rather than at its end, as an identifier is
     */
    public function __construct(
        public string $label,
        public int $minimum = 8,
        public int $priority = 0,
        public bool $right = false,
        public bool $middle = false,
    ) {
    }
}
