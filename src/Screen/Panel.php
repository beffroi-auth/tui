<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Screen\Widget\BarWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * A part of a section under a title of its own, which takes the height its section leaves it: beside another part on a
 * wide terminal, above it on a narrow one (`Theme::SIDE_BY_SIDE`).
 */
final readonly class Panel
{
    public ContainerWidget $widget;

    private BarWidget $title;

    /**
     * @param list<AbstractWidget> $content
     * @param bool                 $expanded whether it takes the height its section leaves, or only the rows it needs
     * @param int                  $weight   its share of the width beside the other parts, from 1 to 3 (`Theme`)
     */
    public function __construct(array $content, bool $expanded = true, int $weight = 1)
    {
        $this->title = new BarWidget()->addStyleClass('panel-title');
        $this->widget = new ContainerWidget()
            ->addStyleClass('panel')
            ->addStyleClass('weight-' . $weight)
            ->expandVertically($expanded);
        $this->widget->add($this->title);
        foreach ($content as $widget) {
            $this->widget->add($widget);
        }
    }

    /**
     * Parts side by side, or one above the other, as the width allows.
     *
     * @param list<self> $panels
     */
    public static function split(array $panels): ContainerWidget
    {
        $split = new ContainerWidget()
            ->addStyleClass('split')
            ->expandVertically(true);
        foreach ($panels as $panel) {
            $split->add($panel->widget);
        }

        return $split;
    }

    /**
     * Its share of the width beside the other parts, which a part that comes to show more takes more of.
     */
    public function weigh(int $weight): void
    {
        foreach ([1, 2, 3] as $other) {
            $this->widget->removeStyleClass('weight-' . $other);
        }
        $this->widget->addStyleClass('weight-' . $weight);
    }

    public function title(string $title, string $note = ''): void
    {
        $this->title->set($title, $note);
    }
}
