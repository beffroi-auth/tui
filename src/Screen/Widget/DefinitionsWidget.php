<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function array_chunk;
use function array_slice;
use function ceil;
use function count;
use function implode;
use function intdiv;
use function max;
use function min;
use function sprintf;
use function str_repeat;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Labels and their values one under the other, the values aligned, a value wider than what is left of its line folded
 * onto the lines under it while the height spares them and cut with an ellipsis once it does not. When the definitions
 * do not fit the height, they are laid in two columns if the width allows it, and the last line says how many were
 * left out otherwise.
 */
final class DefinitionsWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    use ExpandsVerticallyTrait;

    /**
     * The columns a value is given at the least before the definitions are laid in two columns.
     */
    private const int VALUE_IN_TWO_COLUMNS = 24;

    /**
     * @var list<Definition>
     */
    private array $definitions = [];

    private string $more = '%d more';

    /**
     * @param bool $expanded whether it takes the height left by its siblings, or only the rows it needs
     */
    public function __construct(bool $expanded = false)
    {
        $this->expanded = $expanded;
    }

    /**
     * @param list<Definition> $definitions
     * @param string           $more        what the last line says of the definitions left out, "%d" being their number
     */
    public function setDefinitions(array $definitions, string $more = '%d more'): static
    {
        $this->definitions = $definitions;
        $this->more = $more;
        $this->invalidate();

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->definitions === [];
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        if ($this->definitions === []) {
            return [];
        }
        $width = $context->getColumns();
        $height = max(1, $context->getRows());
        if (count($this->definitions) <= $height) {
            return $this->column($this->definitions, $width, $height);
        }
        $half = intdiv($width - 3, 2);
        if (count($this->definitions) <= (2 * $height) && ($half - $this->labelWidth($half) - 2) >= self::VALUE_IN_TWO_COLUMNS) {
            [$left, $right] = array_chunk($this->definitions, max(1, (int) ceil(count($this->definitions) / 2)));
            $leftLines = $this->column($left, $half, $height);
            $rightLines = $this->column($right, $half, $height);
            $lines = [];
            foreach ($leftLines as $row => $line) {
                $lines[] = Fit::cell($line, $half) . ' ' . $this->applyElement('rule', '│') . ' ' . ($rightLines[$row] ?? '');
            }

            return $lines;
        }
        $lines = $this->column(array_slice($this->definitions, 0, $height - 1), $width, $height - 1);
        $lines[] = $this->applyElement('more', Fit::line(sprintf($this->more, count($this->definitions) - $height + 1), $width));

        return $lines;
    }

    /**
     * Each definition on its line, a value too wide for it folded onto the lines under it as long as the height leaves
     * lines to spare, the first values served first, and cut with an ellipsis once it does not.
     *
     * @param list<Definition> $definitions
     *
     * @return list<string>
     */
    private function column(array $definitions, int $width, int $height): array
    {
        $labelWidth = $this->labelWidth($width, $definitions);
        $valueWidth = max(1, $width - $labelWidth - 2);
        $spare = max(0, $height - count($definitions));
        $lines = [];
        foreach ($definitions as $definition) {
            if ($definition->isHeading()) {
                $lines[] = $this->applyElement('heading', Fit::line($definition->label, $width));

                continue;
            }
            $folded = TextWrapper::wrapTextWithAnsi((string) $definition->value, $valueWidth);
            $kept = min(count($folded), 1 + $spare);
            $spare -= $kept - 1;
            foreach (array_slice($folded, 0, $kept) as $row => $line) {
                if ($row === ($kept - 1) && $kept < count($folded)) {
                    $line .= ' ' . implode(' ', array_slice($folded, $kept));
                }
                $label = $row === 0 ? $this->applyElement('label', Fit::cell($definition->label, $labelWidth)) : str_repeat(' ', $labelWidth);
                $lines[] = $label . '  ' . Fit::line($line, $valueWidth);
            }
        }

        return $lines;
    }

    /**
     * As wide as the widest label when the values leave it the room, and otherwise two fifths of the width or what the
     * widest value leaves, whichever is more.
     *
     * @param list<Definition>|null $definitions
     */
    private function labelWidth(int $width, ?array $definitions = null): int
    {
        $widestLabel = 0;
        $widestValue = 0;
        foreach ($definitions ?? $this->definitions as $definition) {
            if (! $definition->isHeading()) {
                $widestLabel = max($widestLabel, Fit::width($definition->label));
                $widestValue = max($widestValue, Fit::width((string) $definition->value));
            }
        }

        return max(1, min($widestLabel, max(intdiv($width * 2, 5), $width - 2 - $widestValue)));
    }
}
