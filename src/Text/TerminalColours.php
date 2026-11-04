<?php

declare(strict_types=1);

namespace Beffroi\Tui\Text;

use function is_string;

/**
 * Whether the screen may draw in colours: always, unless `NO_COLOR` is set to anything but the empty string, as
 * https://no-color.org asks of a program that colours its output by default.
 */
final readonly class TerminalColours
{
    /**
     * @param array<array-key, mixed> $environment
     */
    public static function allowed(array $environment): bool
    {
        $refusal = $environment['NO_COLOR'] ?? null;

        return ! is_string($refusal) || $refusal === '';
    }
}
