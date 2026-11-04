<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use function intdiv;
use function max;
use function mb_strlen;
use function mb_substr;
use function str_repeat;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * What a widget of this screen writes in the room it is given: a line cut to its width with an ellipsis rather than
 * folded onto the next row, and a cell padded to the exact width of its column. symfony/tui refuses a line wider than
 * the widget, and a widget that writes more rows than it was given pushes the screen up, so nothing here ever writes
 * past either.
 */
final readonly class Fit
{
    public const string ELLIPSIS = '…';

    /**
     * The text, cut with an ellipsis when it is wider than the width.
     */
    public static function line(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        return AnsiUtils::visibleWidth($text) <= $width ? $text : AnsiUtils::truncateToWidth($text, $width, self::ELLIPSIS);
    }

    /**
     * The text cut in its middle when it is wider than the width, its beginning and its end kept: what tells two
     * identifiers apart is as often at their end as at their beginning. The text carries no style.
     */
    public static function middle(string $text, int $width): string
    {
        $length = mb_strlen($text);
        if ($width <= 0 || self::width($text) <= $width) {
            return self::line($text, $width);
        }
        if ($width < 5) {
            return self::line($text, $width);
        }
        $head = intdiv($width - 1, 2);
        $tail = $width - 1 - $head;

        return mb_substr($text, 0, $head) . self::ELLIPSIS . mb_substr($text, $length - $tail);
    }

    /**
     * The text cut to the width and padded to it, on the left for a column aligned to the right.
     */
    public static function cell(string $text, int $width, bool $right = false): string
    {
        $fitted = self::line($text, $width);
        $padding = str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($fitted)));

        return $right ? $padding . $fitted : $fitted . $padding;
    }

    /**
     * The text in the middle of the width.
     */
    public static function centered(string $text, int $width): string
    {
        $fitted = self::line($text, $width);

        return str_repeat(' ', intdiv(max(0, $width - AnsiUtils::visibleWidth($fitted)), 2)) . $fitted;
    }

    /**
     * The text on the left and the other on the right of the same line, the one on the right dropped when both do not
     * fit, then the one on the left cut.
     */
    public static function spread(string $left, string $right, int $width): string
    {
        $rightWidth = AnsiUtils::visibleWidth($right);
        $leftWidth = AnsiUtils::visibleWidth($left);
        if ($right === '' || ($leftWidth + 2 + $rightWidth) > $width) {
            return self::cell($left, $width);
        }

        return $left . str_repeat(' ', $width - $leftWidth - $rightWidth) . $right;
    }

    public static function width(string $text): int
    {
        return AnsiUtils::visibleWidth($text);
    }

    /**
     * The text without the sequences that style it, for a line a style of its own is laid over whole.
     */
    public static function plain(string $text): string
    {
        return AnsiUtils::stripAnsiCodes($text);
    }
}
