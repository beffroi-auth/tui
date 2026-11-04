<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_slice;
use function count;
use function max;
use function min;
use function sprintf;
use function str_repeat;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The points of the health of the instance, each marked by a square and a word, never by a colour alone, then what
 * the point found, as many lines of it as the height leaves once every point has its line, the points that do not hold
 * served first.
 */
final class ChecksWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    use ExpandsVerticallyTrait;

    /**
     * @var list<Check>
     */
    private array $checks = [];

    private string $holds = 'ok';

    private string $fails = 'failed';

    private string $empty = '';

    private string $more = '%d more';

    /**
     * @param list<Check> $checks
     * @param string      $holds  the word of a point that holds
     * @param string      $fails  the word of a point that does not
     */
    public function setChecks(array $checks, string $holds, string $fails, string $empty, string $more = '%d more'): static
    {
        $this->checks = $checks;
        $this->holds = $holds;
        $this->fails = $fails;
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
        if ($this->checks === []) {
            return [$this->applyElement('empty', Fit::line($this->empty, $width))];
        }
        $height = max(1, $context->getRows());
        $wordWidth = max(Fit::width($this->holds), Fit::width($this->fails));
        if (count($this->checks) > $height) {
            $lines = [];
            foreach (array_slice($this->checks, 0, $height - 1) as $check) {
                $lines[] = $this->headline($check, $wordWidth, $width);
            }
            $lines[] = $this->applyElement('more', Fit::line(sprintf($this->more, count($this->checks) - $height + 1), $width));

            return $lines;
        }
        $found = $this->foundLines($height - count($this->checks));
        $lines = [];
        foreach ($this->checks as $index => $check) {
            $lines[] = $this->headline($check, $wordWidth, $width);
            foreach (array_slice($check->lines, 0, $found[$index]) as $line) {
                $lines[] = $this->applyElement('found', Fit::line(str_repeat(' ', $wordWidth + 4) . $line, $width));
            }
        }

        return $lines;
    }

    private function headline(Check $check, int $wordWidth, int $width): string
    {
        $mark = $this->applyElement($check->green ? 'holds' : 'fails', '■');
        $word = $check->green ? Fit::cell($this->holds, $wordWidth) : $this->applyElement('fails', Fit::cell($this->fails, $wordWidth));

        return Fit::line($mark . ' ' . $word . '  ' . $check->about, $width);
    }

    /**
     * How many lines of what each point found the spare rows hold, the points that do not hold served first.
     *
     * @return array<int, int>
     */
    private function foundLines(int $spare): array
    {
        $found = [];
        foreach ([false, true] as $green) {
            foreach ($this->checks as $index => $check) {
                if ($check->green !== $green) {
                    continue;
                }
                $found[$index] = min(count($check->lines), max(0, $spare));
                $spare -= $found[$index];
            }
        }

        return $found;
    }
}
