<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use Symfony\Component\Tui\Widget\InputWidget;

/**
 * A field whose Enter does something else than looking up what was typed, which the last line of the screen says while
 * the field has the focus.
 */
final class FieldWidget extends InputWidget
{
    /**
     * @param string $submits the key of the catalogue that names what Enter does
     */
    public function __construct(
        public readonly string $submits,
    ) {
        parent::__construct();
    }
}
