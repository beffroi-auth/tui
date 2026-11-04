<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use function hexdec;
use function preg_match;
use function strlen;

/**
 * The colourway the symbol is drawn in on a terminal, read from the colour of its background: the screen asks it with
 * the query of xterm's control sequences, OSC 11 with "?", which most terminals answer with "rgb:RRRR/GGGG/BBBB", one
 * to four hexadecimal digits a channel. A background whose luma is under half is dark. A terminal that does not answer
 * leaves the symbol mono.
 */
final readonly class TerminalBackground
{
    public const string QUERY = "\e]11;?\e\\";

    private const string ANSWER = '/^\e\]11;rgba?:([0-9a-f]{1,4})\/([0-9a-f]{1,4})\/([0-9a-f]{1,4})(?:\/[0-9a-f]{1,4})?(?:\x07|\e\\\\)$/iD';

    /**
     * @return Colourway|null null when what the terminal sent is not the answer to the query
     */
    public static function colourwayOf(string $answer): ?Colourway
    {
        if (preg_match(self::ANSWER, $answer, $channels) !== 1) {
            return null;
        }
        $luma = (0.299 * self::channel($channels[1])) + (0.587 * self::channel($channels[2])) + (0.114 * self::channel($channels[3]));

        return $luma < 0.5 ? Colourway::BichromeDark : Colourway::Bichrome;
    }

    /**
     * A channel from 0 to 1, whatever number of digits the terminal wrote it with.
     */
    private static function channel(string $digits): float
    {
        return hexdec($digits) / (16 ** strlen($digits) - 1);
    }
}
