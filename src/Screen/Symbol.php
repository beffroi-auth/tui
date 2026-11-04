<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use function array_key_last;
use function dirname;
use function file_get_contents;
use function is_readable;
use LogicException;
use function rtrim;
use function sprintf;

/**
 * The symbol of the brand in a terminal, in the S cut of the terminal support of the brand kit (3-Supports/Terminal):
 * half blocks the generator of the kit draws from its master files, copied into resources/symbol as they are and
 * never drawn, stretched or coloured here. The kit gives the 24 columns to the home screen of a TUI; a terminal too
 * short for them is given the 20 columns, then the 11.
 */
final readonly class Symbol
{
    /**
     * The cuts by their width in columns, the largest first, and the lines each one takes.
     *
     * @var array<int, int>
     */
    private const array CUTS = [24 => 19, 20 => 16, 11 => 8];

    private function __construct(
        public int $columns,
    ) {
    }

    /**
     * The largest cut that leaves the screen the lines it takes besides the symbol, the smallest when none does.
     */
    public static function fitting(int $rows, int $besides): self
    {
        foreach (self::CUTS as $columns => $lines) {
            if (($lines + $besides) <= $rows) {
                return new self($columns);
            }
        }

        return new self((int) array_key_last(self::CUTS));
    }

    /**
     * @throws LogicException when the file of that cut is not where this program ships it
     */
    public function drawn(Colourway $colourway): string
    {
        $path = sprintf('%s/beffroi-symbol-S-%d-%s', self::directory(), $this->columns, $colourway->value);
        $drawn = is_readable($path) ? file_get_contents($path) : false;
        if ($drawn === false) {
            throw new LogicException(sprintf('The symbol %s is not where this program ships it.', $path));
        }

        return rtrim($drawn, "\n");
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/resources/symbol';
    }
}
