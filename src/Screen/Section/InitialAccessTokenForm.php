<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Text\TerminalText;
use function ctype_digit;
use function explode;
use function is_bool;
use function is_string;
use function trim;

/**
 * What the administrator asks of a new initial access token of the tenant (RFC 7591 §1.2): its name, how many clients
 * it may register, for how many days, whether they are clients of the administration, and the APIs it gives them, the
 * document the administration API issues it from (decision 0038).
 */
final class InitialAccessTokenForm
{
    /**
     * Each member of the form, in the order of its rows, with the value it starts with: a boolean is turned over by
     * Enter rather than typed.
     *
     * @var array<string, string|bool>
     */
    private const array MEMBERS = [
        'name' => '',
        'registrations' => '1',
        'days' => '30',
        'administration' => false,
        'resources' => '',
    ];

    /**
     * @var array<string, string|bool>
     */
    private array $values = self::MEMBERS;

    /**
     * The name of the member of that row, null for a row the form does not have.
     */
    public function member(int $row): ?string
    {
        return array_keys(self::MEMBERS)[$row] ?? null;
    }

    public function isFlag(string $member): bool
    {
        return is_bool(self::MEMBERS[$member] ?? null);
    }

    /**
     * What the field of that member starts with: the value the form holds for it.
     */
    public function text(string $member): string
    {
        $value = $this->values[$member] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * A value typed for that member, trimmed; a member turned over rather than typed keeps its value.
     */
    public function write(string $member, string $typed): void
    {
        if (array_key_exists($member, $this->values) && ! $this->isFlag($member)) {
            $this->values[$member] = trim($typed);
        }
    }

    /**
     * A yes for a no, and a no for a yes.
     */
    public function turnOver(string $member): void
    {
        if ($this->isFlag($member)) {
            $this->values[$member] = $this->values[$member] !== true;
        }
    }

    /**
     * The document the token is issued from: a whole number typed is sent as one, and anything else as it was typed,
     * which the API refuses in its own words; the APIs typed, separated by commas, are what the token gives.
     *
     * @return array<string, mixed>
     */
    public function asked(): array
    {
        $asked = [
            'name' => $this->text('name'),
            'registrations' => self::number($this->text('registrations')),
            'days' => self::number($this->text('days')),
            'administration' => $this->values['administration'] === true,
        ];
        $resources = array_values(array_filter(
            array_map(trim(...), explode(',', $this->text('resources'))),
            static fn (string $resource): bool => $resource !== '',
        ));
        if ($resources !== []) {
            $asked['granted'] = [
                'resources' => $resources,
            ];
        }

        return $asked;
    }

    /**
     * @return list<list<string>> each member, and the value the form holds for it
     */
    public function rows(SectionContext $context): array
    {
        $rows = [];
        foreach ($this->values as $member => $value) {
            $rows[] = [
                $member,
                is_bool($value) ? $context->yesOrNo($value) : TerminalText::line($value === '' ? '-' : $value)->value,
            ];
        }

        return $rows;
    }

    private static function number(string $typed): int|string
    {
        return ctype_digit($typed) ? (int) $typed : $typed;
    }
}
