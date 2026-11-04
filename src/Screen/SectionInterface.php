<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * One entry of the navigation of the screen and what it shows once an administrator is signed in. A section of the
 * administration of a tenant is a class of this interface, added to the list `Wiring` hands the screen.
 *
 * A section draws itself in a widget of its own, the same one every time it is shown, so that what it read stays
 * shown while another section is. What it reads of the administration API it reads on the event loop, and it asks
 * for the screen to be drawn again once it has an answer.
 */
interface SectionInterface
{
    /**
     * The identifier of its label in the catalogue of this program.
     */
    public function title(): string;

    /**
     * The widget the section is drawn in, every value that did not come from this program passed through
     * `TerminalText` on its way there.
     */
    public function widget(): AbstractWidget;

    /**
     * The section is shown: one that reads the API reads it the first time it is shown, and keeps what it read.
     */
    public function show(SectionContext $context): void;

    /**
     * The administrator asks for the section again: what it shows is read again.
     */
    public function reload(SectionContext $context): void;

    /**
     * The widgets that take the keys once the administrator enters the section, in the order Tab goes through them;
     * none for a section that only shows.
     *
     * @return list<AbstractWidget&FocusableInterface>
     */
    public function focusables(): array;
}
