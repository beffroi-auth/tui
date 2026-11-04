<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

/**
 * A label and its value, or a heading over the definitions that follow it when it has no value.
 */
final readonly class Definition
{
    /**
     * @param string      $label the label, this program's
     * @param string|null $value the value, through `TerminalText` when it did not come from this program; null for a heading
     */
    public function __construct(
        public string $label,
        public ?string $value,
    ) {
    }

    public static function heading(string $label): self
    {
        return new self($label, null);
    }

    public function isHeading(): bool
    {
        return $this->value === null;
    }
}
