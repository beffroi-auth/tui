<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use Closure;
use function count;
use function implode;
use function max;
use function min;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The sections of the screen, one of them shown: a column of labels beside the section on a terminal at least as wide
 * as the width it is given, a row of tabs above it on a narrower one, the width at which the style sheet lays the
 * screen out one way or the other. symfony/tui hands a widget the colours of its style and none of its layout, so the
 * widget reads the width of the terminal itself. As a column it is as wide as its longest label and as tall as the room
 * it is given, and as a row it shows the tabs around the one shown when they do not all fit.
 *
 * Once it has the focus, the arrows choose a section and Enter goes into it.
 */
final class NavigationWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /**
     * @var list<string>
     */
    private array $labels = [];

    private int $shown = 0;

    /**
     * @var (Closure(int): void)|null
     */
    private ?Closure $moved = null;

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $entered = null;

    private bool $horizontal = false;

    /**
     * @param int                 $wide    the columns from which the sections are a column rather than tabs
     * @param Closure(): int|null $columns the columns of the terminal, asked each time the widget is drawn
     */
    public function __construct(
        private readonly int $wide,
        private ?Closure $columns = null,
    ) {
    }

    /**
     * @param Closure(): int $columns the columns of the terminal, asked each time the widget is drawn
     */
    public function followTheTerminal(Closure $columns): static
    {
        $this->columns = $columns;
        $this->invalidate();

        return $this;
    }

    /**
     * Whether the sections are tabs above the section shown, at the width the terminal has now.
     */
    public function isHorizontal(): bool
    {
        return $this->columns !== null && ($this->columns)() < $this->wide;
    }

    public function beforeRender(): void
    {
        $horizontal = $this->isHorizontal();
        if ($horizontal !== $this->horizontal) {
            $this->horizontal = $horizontal;
            $this->invalidate();
        }
    }

    /**
     * @param list<string> $labels
     */
    public function setLabels(array $labels): static
    {
        $this->labels = $labels;
        $this->shown = min($this->shown, max(0, count($labels) - 1));
        $this->invalidate();

        return $this;
    }

    public function shown(): int
    {
        return $this->shown;
    }

    /**
     * @param Closure(int): void $callback told the section chosen
     */
    public function onMove(Closure $callback): static
    {
        $this->moved = $callback;

        return $this;
    }

    /**
     * @param Closure(): void $callback told that Enter goes into the section shown
     */
    public function onEnter(Closure $callback): static
    {
        $this->entered = $callback;

        return $this;
    }

    public function show(int $section): void
    {
        $section = max(0, min(count($this->labels) - 1, $section));
        if ($section === $this->shown) {
            return;
        }
        $this->shown = $section;
        $this->invalidate();
        if ($this->moved !== null) {
            ($this->moved)($section);
        }
    }

    public function handleInput(string $data): void
    {
        if ($this->onInput !== null && ($this->onInput)($data)) {
            return;
        }
        $keys = $this->getKeybindings();
        // the arrows of both directions, whichever way the sections are laid out, so that a hand used to the other
        // layout finds its way
        if ($keys->matches($data, 'up') || $keys->matches($data, 'left')) {
            $this->show($this->shown === 0 ? count($this->labels) - 1 : $this->shown - 1);
        } elseif ($keys->matches($data, 'down') || $keys->matches($data, 'right')) {
            $this->show($this->shown === (count($this->labels) - 1) ? 0 : $this->shown + 1);
        } elseif ($keys->matches($data, 'enter') && $this->entered !== null) {
            ($this->entered)();
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        return $this->horizontal ? [$this->tabs($context->getColumns())] : $this->column($context->getColumns(), $context->getRows());
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'left' => [Key::LEFT],
            'right' => [Key::RIGHT],
            'enter' => [Key::ENTER],
        ];
    }

    /**
     * Each label on a line of the width of the longest, so that the one shown is marked over the whole of it.
     *
     * @return list<string>
     */
    private function column(int $width, int $height): array
    {
        $widest = 0;
        foreach ($this->labels as $label) {
            $widest = max($widest, Fit::width($label) + 2);
        }
        $width = min($width, $widest);
        $first = max(0, min($this->shown - $height + 1, count($this->labels) - $height));
        $lines = [];
        foreach ($this->labels as $index => $label) {
            if ($index < $first || count($lines) >= max(1, $height)) {
                continue;
            }
            $line = Fit::cell(' ' . $label, $width);
            $lines[] = $index === $this->shown ? $this->applyElement('selected', $line) : $this->applyElement('item', $line);
        }
        // as tall as the room it is given, so that the rule beside it runs the height of the screen
        while (count($lines) < $height) {
            $lines[] = Fit::cell('', $width);
        }

        return $lines;
    }

    /**
     * The tabs on one line, the ones around the tab shown when they do not all fit.
     */
    private function tabs(int $width): string
    {
        $tabs = [];
        foreach ($this->labels as $index => $label) {
            $tabs[$index] = ' ' . $label . ' ';
        }
        $from = 0;
        $to = count($tabs) - 1;
        while ($this->tabsWidth($tabs, $from, $to) > $width && $from < $to) {
            if (($this->shown - $from) > ($to - $this->shown)) {
                ++$from;
            } else {
                --$to;
            }
        }
        $line = [];
        for ($index = $from; $index <= $to; ++$index) {
            $line[] = $index === $this->shown ? $this->applyElement('selected', $tabs[$index]) : $this->applyElement('item', $tabs[$index]);
        }

        return Fit::line(($from > 0 ? '‹' : ' ') . implode($this->applyElement('rule', '│'), $line) . ($to < (count($tabs) - 1) ? '›' : ''), $width);
    }

    /**
     * @param array<int, string> $tabs
     */
    private function tabsWidth(array $tabs, int $from, int $to): int
    {
        $width = 2;
        for ($index = $from; $index <= $to; ++$index) {
            $width += Fit::width($tabs[$index]) + 1;
        }

        return $width;
    }
}
