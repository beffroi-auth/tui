<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_slice;
use function max;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * What a part of the screen says when it has nothing else to show: that it is being read, that there is nothing to
 * show, or why it could not be read, folded to the width and cut to the height.
 */
final class MessageWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    use ExpandsVerticallyTrait {
        isVerticallyExpanded as private expandedWhenItSaysSomething;
    }

    private string $text = '';

    /**
     * @param bool $expanded whether it takes the height left by its siblings, or only the rows it needs
     */
    public function __construct(bool $expanded = false)
    {
        $this->expanded = $expanded;
    }

    /**
     * A message that says nothing takes no room, expanded or not.
     */
    public function isVerticallyExpanded(): bool
    {
        return $this->text !== '' && $this->expandedWhenItSaysSomething();
    }

    private bool $error = false;

    public function say(string $text, bool $error = false): static
    {
        $this->text = $text;
        $this->error = $error;
        $this->invalidate();

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        if ($this->text === '') {
            return [];
        }
        $lines = [];
        foreach (array_slice(TextWrapper::wrapTextWithAnsi($this->text, max(1, $context->getColumns())), 0, max(1, $context->getRows())) as $line) {
            $lines[] = $this->applyElement($this->error ? 'error' : 'text', Fit::line($line, $context->getColumns()));
        }

        return $lines;
    }
}
