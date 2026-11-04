<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_slice;
use function count;
use function intdiv;
use function max;
use function mb_substr;
use function min;
use function sprintf;
use function str_repeat;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Counts as horizontal bars, one series: each label, its bar as long as its count against the largest one, flat at the
 * ends and drawn to the eighth of a column, and the figure after it. The labels are in the colour of the text and never
 * in the colour of the bars, and the bars that fit the height are drawn, the last line saying how many were left out.
 */
final class ChartWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    use ExpandsVerticallyTrait;

    private const string EIGHTHS = ' ▏▎▍▌▋▊▉';

    /**
     * @var list<ChartBar>
     */
    private array $bars = [];

    private string $empty = '';

    private string $more = '%d more';

    /**
     * @param list<ChartBar> $bars
     * @param string         $more what the last line says of the bars left out, "%d" being their number
     */
    public function setBars(array $bars, string $empty, string $more = '%d more'): static
    {
        $this->bars = $bars;
        $this->empty = $empty;
        $this->more = $more;
        $this->invalidate();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        if ($this->bars === []) {
            return [$this->applyElement('empty', Fit::line($this->empty, $width))];
        }
        $height = max(1, $context->getRows());
        $shown = count($this->bars) <= $height ? $this->bars : array_slice($this->bars, 0, $height - 1);
        $largest = 1;
        $labelWidth = 0;
        $figureWidth = 1;
        foreach ($this->bars as $bar) {
            $largest = max($largest, $bar->count);
            $labelWidth = max($labelWidth, Fit::width($bar->label));
            $figureWidth = max($figureWidth, Fit::width((string) $bar->count));
        }
        $labelWidth = min($labelWidth, intdiv($width * 2, 5));
        $barWidth = max(1, $width - $labelWidth - $figureWidth - 4);
        $lines = [];
        foreach ($shown as $bar) {
            $lines[] = Fit::line(
                Fit::cell($bar->label, $labelWidth)
                    . '  '
                    . $this->applyElement($bar->failure ? 'failure' : 'series', self::bar($bar->count, $largest, $barWidth))
                    . '  '
                    . Fit::cell((string) $bar->count, $figureWidth, true),
                $width,
            );
        }
        if (count($shown) < count($this->bars)) {
            $lines[] = $this->applyElement('more', Fit::line(sprintf($this->more, count($this->bars) - count($shown)), $width));
        }

        return $lines;
    }

    /**
     * The bar of a count, in eighths of a column, padded to the width of every bar.
     */
    private static function bar(int $count, int $largest, int $width): string
    {
        $eighths = intdiv($count * $width * 8, $largest);
        if ($count > 0 && $eighths === 0) {
            $eighths = 1;
        }
        $bar = str_repeat('█', intdiv($eighths, 8));
        $rest = $eighths % 8;
        if ($rest > 0) {
            $bar .= mb_substr(self::EIGHTHS, $rest, 1);
        }

        return Fit::cell($bar, $width);
    }
}
