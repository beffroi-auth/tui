<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * One line across the screen, the header or the footer: a text on the left and one on the right, the one on the right
 * dropped and the one on the left cut when the width does not hold both, the line padded so that its background
 * covers the whole width.
 */
final class BarWidget extends AbstractWidget
{
    private string $left = '';

    private string $right = '';

    public function set(string $left, string $right = ''): static
    {
        if ($left !== $this->left || $right !== $this->right) {
            $this->left = $left;
            $this->right = $right;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        return [Fit::cell(Fit::spread($this->left, $this->right, $context->getColumns()), $context->getColumns())];
    }
}
