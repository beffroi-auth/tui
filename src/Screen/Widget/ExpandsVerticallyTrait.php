<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * Whether a widget takes the height its siblings leave in its container (`VerticallyExpandableInterface`), which is
 * how it is given a number of rows to fit rather than drawing as many as it has.
 */
trait ExpandsVerticallyTrait
{
    private bool $expanded = true;

    public function expandVertically(bool $expand): static
    {
        if ($this->expanded !== $expand) {
            $this->expanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }
}
