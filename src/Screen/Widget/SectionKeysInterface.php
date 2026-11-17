<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A widget on which a section answers keys of its own, which the last line of the screen says while it has the focus.
 */
interface SectionKeysInterface
{
    /**
     * @return list<array{string, string}> each key as it is written, and what it does
     */
    public function sectionKeys(): array;
}
