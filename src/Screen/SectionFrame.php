<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Screen\Widget\BarWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * What every section is drawn in: a heading line that names it and says where its reading stands, the message of a
 * reading under way or refused, and what the section shows, in the height the screen leaves it.
 */
final readonly class SectionFrame
{
    public ContainerWidget $widget;

    public MessageWidget $message;

    public Reading $reading;

    private BarWidget $heading;

    public function __construct()
    {
        $this->heading = new BarWidget()->addStyleClass('heading');
        $this->message = new MessageWidget();
        $this->reading = new Reading($this->message);
        $this->widget = new ContainerWidget()
            ->addStyleClass('section')
            ->expandVertically(true);
        $this->widget->add($this->heading);
        $this->widget->add($this->message);
    }

    public function add(AbstractWidget $widget): void
    {
        $this->widget->add($widget);
    }

    /**
     * @param string $title the name of the section
     * @param string $note  what the right of the heading says, the moment the section was read for instance
     */
    public function title(string $title, string $note = ''): void
    {
        $this->heading->set($title, $note);
    }
}
