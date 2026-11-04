<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A point of the health of the instance: whether it holds, what it is about, and what was found of it.
 */
final readonly class Check
{
    /**
     * @param list<string> $lines what was found, through `TerminalText`
     */
    public function __construct(
        public bool $green,
        public string $about,
        public array $lines,
    ) {
    }
}
