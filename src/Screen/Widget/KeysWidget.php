<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_slice;
use Closure;
use function count;
use function implode;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The keys the screen answers to, each one marked and followed by what it does, in the order of what matters most: the
 * last ones are left out first when the line is too narrow for all of them. What the screen says on the right of the
 * line, the moment a section was read for instance, goes before them.
 */
final class KeysWidget extends AbstractWidget
{
    /**
     * @var list<array{string, string}>
     */
    private array $keys = [];

    private string $note = '';

    /**
     * @var (Closure(): list<array{string, string}>)|null
     */
    private ?Closure $source = null;

    /**
     * @param list<array{string, string}> $keys each key as it is written, and what it does
     */
    public function setKeys(array $keys): static
    {
        $this->keys = $keys;
        $this->invalidate();

        return $this;
    }

    /**
     * The keys are asked each time the screen is drawn, so that they follow the widget that has the focus.
     *
     * @param Closure(): list<array{string, string}> $source
     */
    public function follow(Closure $source): static
    {
        $this->source = $source;
        $this->invalidate();

        return $this;
    }

    public function beforeRender(): void
    {
        if ($this->source === null) {
            return;
        }
        $keys = ($this->source)();
        if ($keys !== $this->keys) {
            $this->keys = $keys;
            $this->invalidate();
        }
    }

    public function setNote(string $note): static
    {
        if ($note !== $this->note) {
            $this->note = $note;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $note = $this->note === '' ? '' : $this->applyElement('note', $this->note);
        $kept = count($this->keys);
        $line = $this->hints($kept);
        while ($kept > 0 && (Fit::width($line) + ($note === '' ? 0 : Fit::width($note) + 3)) > $width) {
            $line = $this->hints(--$kept);
        }

        return [Fit::cell(Fit::spread($line, $note . ' ', $width), $width)];
    }

    /**
     * The first keys, each marked and followed by what it does.
     */
    private function hints(int $kept): string
    {
        $hints = [];
        foreach (array_slice($this->keys, 0, $kept) as [$key, $does]) {
            $hints[] = $this->applyElement('key', ' ' . $key . ' ') . ' ' . $this->applyElement('does', $does);
        }

        return ' ' . implode('   ', $hints);
    }
}
