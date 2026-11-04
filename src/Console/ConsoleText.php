<?php

declare(strict_types=1);

namespace Beffroi\Tui\Console;

use Beffroi\Tui\Text\TerminalText;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * What a line of the console is handed of a string this program did not write, an exception message that quotes
 * the provider included: the controls `TerminalText` removes, and the markup of the console formatter escaped, so
 * that `<href=…>` or `<fg=…>` in a name the provider answered is shown and not obeyed.
 */
final readonly class ConsoleText
{
    /**
     * For a line the formatter reads as it is, `writeln()` or a cell of `definitionList()`.
     */
    public static function of(string $untrusted): string
    {
        return OutputFormatter::escape(self::inBlock($untrusted));
    }

    /**
     * For a block of SymfonyStyle, `error()`, `note()` or `success()`, which escapes the markup of its message itself:
     * escaped twice, a "<" would be shown as "\<".
     */
    public static function inBlock(string $untrusted): string
    {
        return TerminalText::of($untrusted)->value;
    }
}
