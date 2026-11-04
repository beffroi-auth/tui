<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_chunk;
use function array_map;
use function array_slice;
use function count;
use function implode;
use function intdiv;
use function max;
use function str_repeat;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Figures in boxes side by side, the figure over what it counts, as many boxes on a row as the width holds and as many
 * rows as it takes, in a third of the height of the section at the most: when that does not hold the boxes, the figures
 * are written one after the other on as few lines as they take, which is what a short or a narrow terminal is given.
 */
final class TilesWidget extends AbstractWidget
{
    /**
     * The rows of a box: its top, the figure, what it counts and its bottom.
     */
    private const int BOX_ROWS = 4;

    /**
     * @var list<Tile>
     */
    private array $tiles = [];

    /**
     * @param list<Tile> $tiles
     */
    public function setTiles(array $tiles): static
    {
        $this->tiles = $tiles;
        $this->invalidate();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        if ($this->tiles === []) {
            return [];
        }
        $width = $context->getColumns();
        $inner = 0;
        foreach ($this->tiles as $tile) {
            $inner = max($inner, Fit::width($tile->figure), Fit::width($tile->label));
        }
        $boxWidth = $inner + 4;
        $perRow = max(1, intdiv($width + 1, $boxWidth + 1));
        $rows = array_chunk($this->tiles, $perRow);
        // the figures are a summary over what the section shows: a third of its height at the most
        $allowed = max(1, intdiv($context->getRows(), 3));
        if ($boxWidth > $width || (count($rows) * self::BOX_ROWS) > $allowed) {
            return $this->inline($width, $allowed);
        }
        $boxWidth = max($boxWidth, intdiv($width + 1, $perRow) - 1);
        $lines = [];
        foreach ($rows as $row) {
            $top = [];
            $figures = [];
            $labels = [];
            $bottom = [];
            foreach ($row as $tile) {
                $top[] = $this->applyElement('frame', '┌' . str_repeat('─', $boxWidth - 2) . '┐');
                $figures[] =
                    $this->applyElement('frame', '│')
                    . ' '
                    . $this->applyElement('figure', Fit::cell($tile->figure, $boxWidth - 4))
                    . ' '
                    . $this->applyElement('frame', '│');
                $labels[] =
                    $this->applyElement('frame', '│')
                    . ' '
                    . $this->applyElement('label', Fit::cell($tile->label, $boxWidth - 4))
                    . ' '
                    . $this->applyElement('frame', '│');
                $bottom[] = $this->applyElement('frame', '└' . str_repeat('─', $boxWidth - 2) . '┘');
            }
            foreach ([$top, $figures, $labels, $bottom] as $parts) {
                $lines[] = Fit::line(implode(' ', $parts), $width);
            }
        }

        return $lines;
    }

    /**
     * The figures one after the other, as many on a line as it holds.
     *
     * @return list<string>
     */
    private function inline(int $width, int $height): array
    {
        $parts = array_map(
            fn (Tile $tile): string => $this->applyElement('figure', $tile->figure) . ' ' . $this->applyElement('label', $tile->label),
            $this->tiles,
        );
        $lines = [];
        $line = '';
        foreach ($parts as $part) {
            $candidate = $line === '' ? $part : $line . '   ' . $part;
            if ($line !== '' && Fit::width($candidate) > $width) {
                $lines[] = Fit::line($line, $width);
                $line = $part;

                continue;
            }
            $line = $candidate;
        }
        $lines[] = Fit::line($line, $width);

        return array_slice($lines, 0, max(1, $height));
    }
}
