<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use Closure;
use function array_keys;
use function array_map;
use function array_sum;
use function count;
use function implode;
use function max;
use function min;
use function sprintf;
use function str_repeat;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;
use function uasort;

/**
 * Rows under the headings of their columns, in the room the table is given and never more: each column as wide as what
 * it holds when the width allows, cut with an ellipsis when it does not, and dropped, the one of the lowest priority
 * first, when even its minimum does not fit; the rows that fit the height are shown, the selected one among them, and a
 * last line says which rows of how many when they do not all fit.
 *
 * A table whose rows can be chosen takes the arrows, the page keys, Home and End once it has the focus, and tells what
 * Enter chose. Every cell that did not come from this program reaches it through `TerminalText`.
 */
final class TableWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use ExpandsVerticallyTrait;
    use FocusableTrait {
        setFocused as private focus;
    }
    use KeybindingsTrait;

    private const int GAP = 2;

    /**
     * The columns a column wants at the most before a column of a lower priority is shown beside it.
     */
    private const int WANTED = 32;

    /**
     * @var list<list<string>>
     */
    private array $rows = [];

    private string $empty = '';

    private int $selected = 0;

    private int $first = 0;

    /**
     * The rows the last drawing showed, which the page keys move by.
     */
    private int $page = 1;

    /**
     * @var (Closure(int): void)|null
     */
    private ?Closure $chosen = null;

    /**
     * @var (Closure(int): void)|null
     */
    private ?Closure $moved = null;

    /**
     * @var list<array{string, string}>
     */
    private array $sectionKeys = [];

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $left = null;

    /**
     * @param list<TableColumn> $columns
     * @param bool              $selectable whether a row can be chosen, which only a table with something behind its rows offers
     */
    public function __construct(
        private array $columns,
        private readonly bool $selectable = true,
    ) {
    }

    /**
     * The headings of the columns in the language of the screen, which a section only knows once it is shown.
     *
     * @param list<string> $labels the heading of each column, in their order
     */
    public function setColumnLabels(array $labels): static
    {
        $columns = [];
        foreach ($this->columns as $index => $column) {
            $columns[] = new TableColumn($labels[$index] ?? $column->label, $column->minimum, $column->priority, $column->right, $column->middle);
        }
        $this->columns = $columns;
        $this->invalidate();

        return $this;
    }

    /**
     * @param list<list<string>> $rows  one cell for each column, in the order of the columns
     * @param string             $empty what the table says when it has no row
     */
    public function setRows(array $rows, string $empty = ''): static
    {
        $this->rows = $rows;
        $this->empty = $empty;
        $this->selected = min($this->selected, max(0, count($rows) - 1));
        $this->invalidate();

        return $this;
    }

    /**
     * The row chosen, null when the table has none.
     */
    public function selected(): ?int
    {
        return $this->rows === [] ? null : $this->selected;
    }

    /**
     * @param Closure(int): void $callback told the row Enter chose
     */
    public function onChoose(Closure $callback): static
    {
        $this->chosen = $callback;

        return $this;
    }

    /**
     * @param Closure(int): void $callback told the row the arrows moved to
     */
    public function onMove(Closure $callback): static
    {
        $this->moved = $callback;

        return $this;
    }

    /**
     * The keys a section answers on this table beside its own, which the last line of the screen says while the table
     * has the focus.
     *
     * @param list<array{string, string}> $keys each key as it is written, and what it does
     */
    public function setSectionKeys(array $keys): static
    {
        $this->sectionKeys = $keys;

        return $this;
    }

    /**
     * @return list<array{string, string}>
     */
    public function sectionKeys(): array
    {
        return $this->sectionKeys;
    }

    /**
     * @param Closure(): void $callback told when the focus leaves the table, whatever key moved it
     */
    public function onLeave(Closure $callback): static
    {
        $this->left = $callback;

        return $this;
    }

    public function setFocused(bool $focused): static
    {
        $leaving = $this->isFocused() && ! $focused;
        $this->focus($focused);
        if ($leaving && $this->left !== null) {
            ($this->left)();
        }

        return $this;
    }

    /**
     * Whether Enter does something on a row of this table.
     */
    public function isChoosable(): bool
    {
        return $this->selectable && $this->chosen !== null;
    }

    public function handleInput(string $data): void
    {
        if ($this->onInput !== null && ($this->onInput)($data)) {
            return;
        }
        if (! $this->selectable || $this->rows === []) {
            return;
        }
        $keys = $this->getKeybindings();
        $to = match (true) {
            $keys->matches($data, 'up') => $this->selected - 1,
            $keys->matches($data, 'down') => $this->selected + 1,
            $keys->matches($data, 'page_up') => $this->selected - $this->page,
            $keys->matches($data, 'page_down') => $this->selected + $this->page,
            $keys->matches($data, 'first') => 0,
            $keys->matches($data, 'last') => count($this->rows) - 1,
            default => null,
        };
        if ($to !== null) {
            $this->moveTo($to);

            return;
        }
        if ($keys->matches($data, 'choose') && $this->chosen !== null) {
            ($this->chosen)($this->selected);
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        if ($this->rows === []) {
            return [$this->applyElement('empty', Fit::line($this->empty, $width))];
        }
        $shown = $this->columnsFitting($width);
        $widths = $this->widths($shown, $width);
        $height = max(1, $context->getRows());
        // a row of one line holds the selected row alone; the headings come with a second, and the line that says
        // which rows are shown with a third
        $lines = $height === 1
            ? []
            : [$this->applyElement('header', Fit::cell(
                $this->joined(array_map(fn (int $column): string => $this->columns[$column]->label, $shown), $shown, $widths),
                $width,
            ))];
        $capacity = $height - count($lines);
        $overflowing = count($this->rows) > $capacity && $height >= 3;
        if ($overflowing) {
            --$capacity;
        }
        $this->page = $capacity;
        $this->first = max(0, min($this->first, $this->selected, count($this->rows) - $capacity));
        if ($this->selected >= ($this->first + $capacity)) {
            $this->first = $this->selected - $capacity + 1;
        }
        $last = min(count($this->rows), $this->first + $capacity);
        for ($row = $this->first; $row < $last; ++$row) {
            $line = Fit::cell($this->joined(array_map(fn (int $column): string => $this->rows[$row][$column] ?? '', $shown), $shown, $widths), $width);
            $lines[] = $this->selectable && $row === $this->selected ? $this->applyElement('selected', Fit::plain($line)) : $line;
        }
        if ($overflowing) {
            $lines[] = $this->applyElement('position', Fit::spread('', sprintf('%d-%d / %d', $this->first + 1, $last, count($this->rows)), $width));
        }

        return $lines;
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'first' => [Key::HOME],
            'last' => [Key::END],
            'choose' => [Key::ENTER],
        ];
    }

    private function moveTo(int $row): void
    {
        $row = max(0, min(count($this->rows) - 1, $row));
        if ($row === $this->selected) {
            return;
        }
        $this->selected = $row;
        $this->invalidate();
        if ($this->moved !== null) {
            ($this->moved)($row);
        }
    }

    /**
     * The columns that fit the width, in their order: the ones of the highest priority taken first, each with the
     * width it wants and the gap before it, so that a column is only shown once the ones that matter more are as wide as
     * what they hold, up to `WANTED` columns; the first of them is shown whatever the width.
     *
     * @return list<int>
     */
    private function columnsFitting(int $width): array
    {
        $byPriority = $this->columns;
        uasort($byPriority, static fn (TableColumn $one, TableColumn $other): int => $other->priority <=> $one->priority);
        $taken = [];
        $used = 0;
        foreach (array_keys($byPriority) as $index) {
            $needed = $this->wantedWidth($index) + ($taken === [] ? 0 : self::GAP);
            if ($taken !== [] && ($used + $needed) > $width) {
                continue;
            }
            $taken[$index] = true;
            $used += $needed;
        }
        $shown = [];
        foreach (array_keys($this->columns) as $index) {
            if (isset($taken[$index])) {
                $shown[] = $index;
            }
        }

        return $shown;
    }

    /**
     * What a column wants before a column of a lower priority is given room: what it holds, between its minimum and
     * `WANTED` columns.
     */
    private function wantedWidth(int $column): int
    {
        $natural = Fit::width($this->columns[$column]->label);
        foreach ($this->rows as $row) {
            $natural = max($natural, Fit::width($row[$column] ?? ''));
        }

        return max($this->minimumOf($column), min($natural, self::WANTED));
    }

    /**
     * Each column shown as wide as its widest cell when they all fit, and otherwise its minimum, then one column more
     * at a time to each column that holds wider cells, the ones of the highest priority served first.
     *
     * @param list<int> $shown
     *
     * @return array<int, int> the width of each column shown
     */
    private function widths(array $shown, int $width): array
    {
        $available = max(1, $width - (self::GAP * (count($shown) - 1)));
        $natural = [];
        foreach ($shown as $column) {
            $natural[$column] = Fit::width($this->columns[$column]->label);
            foreach ($this->rows as $row) {
                $natural[$column] = max($natural[$column], Fit::width($row[$column] ?? ''));
            }
        }
        if (array_sum($natural) <= $available) {
            return $natural;
        }
        $widths = [];
        foreach ($shown as $column) {
            $widths[$column] = min($natural[$column], $this->minimumOf($column));
        }
        $order = $shown;
        uasort($order, fn (int $one, int $other): int => $this->columns[$other]->priority <=> $this->columns[$one]->priority);
        $left = $available - array_sum($widths);
        while ($left > 0) {
            $grown = false;
            foreach ($order as $column) {
                if ($left > 0 && $widths[$column] < $natural[$column]) {
                    ++$widths[$column];
                    --$left;
                    $grown = true;
                }
            }
            if (! $grown) {
                break;
            }
        }

        return $widths;
    }

    /**
     * What a column needs to be shown: its minimum, and its heading whole, a column whose heading is cut being a column
     * nobody can read.
     */
    private function minimumOf(int $column): int
    {
        return max($this->columns[$column]->minimum, Fit::width($this->columns[$column]->label));
    }

    /**
     * @param list<string>    $cells
     * @param list<int>       $shown
     * @param array<int, int> $widths
     */
    private function joined(array $cells, array $shown, array $widths): string
    {
        $fitted = [];
        foreach ($shown as $position => $column) {
            $cell = $cells[$position] ?? '';
            if ($this->columns[$column]->middle) {
                $cell = Fit::middle(Fit::plain($cell), $widths[$column]);
            }
            $fitted[] = Fit::cell($cell, $widths[$column], $this->columns[$column]->right);
        }

        return implode(str_repeat(' ', self::GAP), $fitted);
    }
}
