<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Screen\Widget\ChartWidget;
use Beffroi\Tui\Screen\Widget\ChecksWidget;
use Beffroi\Tui\Screen\Widget\DefinitionsWidget;
use Beffroi\Tui\Screen\Widget\KeysWidget;
use Beffroi\Tui\Screen\Widget\MessageWidget;
use Beffroi\Tui\Screen\Widget\NavigationWidget;
use Beffroi\Tui\Screen\Widget\QrCodeWidget;
use Beffroi\Tui\Screen\Widget\TableWidget;
use Beffroi\Tui\Screen\Widget\TilesWidget;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;

/**
 * How the screen is laid out and drawn. The layout follows the width of the terminal: under `WIDE` columns the
 * sections are tabs above what the shown one says, from it they are a column beside it, and from `SIDE_BY_SIDE` the
 * parts of a section stand side by side rather than one above the other. The home page drops the symbol under
 * `SYMBOL` columns.
 *
 * The colours are the brand's (Charte Graphique, p. 21 to 24): ultramarine is the active state, the line of the
 * header and the bars of a chart; accent text is cold light on a dark terminal and accent ink on a light one, and
 * plain bold while the background is not known; an error is drawn in the colour of errors and always says its word;
 * the signal only lights the square of a point that holds. A QR code is black on white, which every reader takes. No
 * corner is rounded. A screen without colours (https://no-color.org) marks the same things in reverse video and in
 * bold.
 */
final readonly class Theme
{
    public const int WIDE = 90;

    public const int SIDE_BY_SIDE = 130;

    public const int SYMBOL = 70;

    private const string ULTRAMARINE = '#2F45FF';

    private const string ON_ULTRAMARINE = '#FFFFFF';

    private const string COLD_LIGHT = '#A9B3FF';

    private const string ACCENT_INK = '#1B2BB8';

    private const string SIGNAL = '#C6F432';

    private const string ERROR = '#B3261E';

    private const string QR_DARK = '#000000';

    private const string QR_LIGHT = '#FFFFFF';

    /**
     * The layout, and what the screen draws before it knows the background of the terminal.
     */
    public static function styleSheet(bool $colours): StyleSheet
    {
        $sheet = new StyleSheet([
            '.page' => new Style(),
            '.home' => new Style(padding: Padding::xy(2, 1), direction: Direction::Horizontal, gap: 4),
            '.symbol' => new Style(flex: 0, hidden: true),
            '.welcome' => new Style(gap: 1, flex: 1),
            '.title' => new Style(bold: true),
            '.body' => new Style(direction: Direction::Vertical, gap: 1),
            '.navigation' => new Style(direction: Direction::Horizontal),
            '.main' => new Style(padding: Padding::xy(1, 0), flex: 1),
            '.section' => new Style(gap: 1),
            '.heading' => new Style(bold: true),
            '.split' => new Style(direction: Direction::Vertical, gap: 1),
            '.panel' => new Style(),
            '.weight-1' => new Style(flex: 1),
            '.weight-2' => new Style(flex: 2),
            '.weight-3' => new Style(flex: 3),
            '.panel-title' => new Style(bold: true, underline: true),
            '.hidden' => new Style(hidden: true),
            TableWidget::class . '::header' => new Style(bold: true),
            TableWidget::class . '::selected' => new Style(bold: true),
            TableWidget::class . '::selected:focus' => new Style(reverse: true),
            TableWidget::class . '::position' => new Style(dim: true),
            TableWidget::class . '::empty' => new Style(dim: true),
            NavigationWidget::class . '::selected' => new Style(bold: true, underline: true),
            NavigationWidget::class . '::selected:focus' => new Style(reverse: true, bold: true),
            NavigationWidget::class . '::rule' => new Style(dim: true),
            DefinitionsWidget::class . '::label' => new Style(dim: true),
            DefinitionsWidget::class . '::heading' => new Style(bold: true),
            DefinitionsWidget::class . '::more' => new Style(dim: true),
            DefinitionsWidget::class . '::rule' => new Style(dim: true),
            KeysWidget::class . '::key' => new Style(reverse: true),
            KeysWidget::class . '::does' => new Style(),
            KeysWidget::class . '::note' => new Style(dim: true),
            TilesWidget::class . '::frame' => new Style(dim: true),
            TilesWidget::class . '::figure' => new Style(bold: true),
            TilesWidget::class . '::label' => new Style(dim: true),
            ChartWidget::class . '::more' => new Style(dim: true),
            ChartWidget::class . '::empty' => new Style(dim: true),
            ChecksWidget::class . '::fails' => new Style(bold: true),
            ChecksWidget::class . '::found' => new Style(dim: true),
            ChecksWidget::class . '::more' => new Style(dim: true),
            ChecksWidget::class . '::empty' => new Style(dim: true),
            MessageWidget::class . '::text' => new Style(dim: true),
            MessageWidget::class . '::error' => new Style(bold: true),
            // reversed, the modules drawn take the background of the terminal: dark on the dark one most terminals have
            QrCodeWidget::class . '::code' => new Style(reverse: true),
            QrCodeWidget::class . '::small' => new Style(dim: true),
            '.header' => new Style(bold: true, reverse: true),
        ]);
        $sheet->addBreakpoint(self::SYMBOL, '.symbol', new Style(hidden: false));
        $sheet->addBreakpoint(self::WIDE, '.body', new Style(direction: Direction::Horizontal, gap: 0));
        $sheet->addBreakpoint(
            self::WIDE,
            '.navigation',
            new Style(direction: Direction::Vertical, padding: Padding::xy(0, 1), border: new Border(0, 1, 0, 0), flex: 0),
        );
        $sheet->addBreakpoint(self::SIDE_BY_SIDE, '.split', new Style(direction: Direction::Horizontal, gap: 3));
        if (! $colours) {
            return $sheet;
        }

        return $sheet->merge(new StyleSheet([
            '.header' => new Style(background: self::ULTRAMARINE, color: self::ON_ULTRAMARINE, bold: true, reverse: false),
            TableWidget::class . '::selected:focus' => new Style(background: self::ULTRAMARINE, color: self::ON_ULTRAMARINE, reverse: false),
            NavigationWidget::class . '::selected:focus' => new Style(background: self::ULTRAMARINE, color: self::ON_ULTRAMARINE, reverse: false),
            KeysWidget::class . '::key' => new Style(background: self::ULTRAMARINE, color: self::ON_ULTRAMARINE, reverse: false),
            ChartWidget::class . '::series' => new Style(color: self::ULTRAMARINE),
            ChecksWidget::class . '::holds' => new Style(color: self::SIGNAL),
            // the red of the terminal until its background is known, which its theme keeps readable on either
            ChartWidget::class . '::failure' => new Style(color: 'red'),
            ChecksWidget::class . '::fails' => new Style(bold: true, color: 'red'),
            MessageWidget::class . '::error' => new Style(bold: true, color: 'red'),
            QrCodeWidget::class . '::code' => new Style(background: self::QR_LIGHT, color: self::QR_DARK, reverse: false),
        ]));
    }

    /**
     * What the screen draws once the terminal said its background: the accent text and the errors in the colours
     * the brand gives that background.
     */
    public static function forBackground(Colourway $colourway): StyleSheet
    {
        $dark = $colourway !== Colourway::Bichrome;
        $accent = $dark ? self::COLD_LIGHT : self::ACCENT_INK;
        $error = $dark ? Color::hex(self::ERROR)->tint(45) : Color::hex(self::ERROR);

        return new StyleSheet([
            '.heading' => new Style(bold: true, color: $accent),
            '.panel-title' => new Style(bold: true, underline: false, color: $accent),
            TableWidget::class . '::header' => new Style(bold: true, color: $accent),
            TableWidget::class . '::selected' => new Style(bold: true, color: $accent),
            NavigationWidget::class . '::selected' => new Style(bold: true, underline: false, color: $accent),
            DefinitionsWidget::class . '::heading' => new Style(bold: true, color: $accent),
            TilesWidget::class . '::figure' => new Style(bold: true, color: $accent),
            ChartWidget::class . '::failure' => new Style(color: $error),
            ChecksWidget::class . '::fails' => new Style(bold: true, color: $error),
            MessageWidget::class . '::error' => new Style(bold: true, color: $error),
        ]);
    }
}
