<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

/**
 * The three colourways of the terminal support of the brand kit (3-Supports/Terminal): mono, in the colour of the text
 * of the terminal with the window cut out of the tower, for a terminal that takes no colour or whose background is not
 * known; bichrome-dark, the tower in paper on a dark terminal; bichrome, the tower in ink on a light one, the window
 * in ultramarine in both. Each is backed by the end of the names of its files.
 */
enum Colourway: string
{
    case Mono = 'mono.txt';
    case BichromeDark = 'bichrome-dark.ansi';
    case Bichrome = 'bichrome.ansi';
}
