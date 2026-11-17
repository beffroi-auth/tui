<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use Closure;
use Symfony\Component\Tui\Widget\InputWidget;

/**
 * A field whose Enter does something else than looking up what was typed, which the last line of the screen says while
 * the field has the focus.
 */
final class FieldWidget extends InputWidget
{
    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $left = null;

    /**
     * @param string $submits the key of the catalogue that names what Enter does
     */
    public function __construct(
        public readonly string $submits,
    ) {
        parent::__construct();
    }

    /**
     * @param Closure(): void $callback told when the focus leaves the field, whatever key moved it
     */
    public function onLeave(Closure $callback): static
    {
        $this->left = $callback;

        return $this;
    }

    public function setFocused(bool $focused): static
    {
        $leaving = $this->isFocused() && ! $focused;
        parent::setFocused($focused);
        if ($leaving && $this->left !== null) {
            ($this->left)();
        }

        return $this;
    }
}
