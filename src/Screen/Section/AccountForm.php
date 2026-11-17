<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Section;

use function array_key_exists;
use function array_keys;
use function array_shift;
use Beffroi\Tui\Json\Document;
use Beffroi\Tui\Screen\SectionContext;
use Beffroi\Tui\Text\TerminalText;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function trim;

/**
 * What the administrator changes of the account of the person shown (decision 0038): a row for each member the
 * administration API changes, prefilled from the person last read, and the values changed and not saved yet, which one
 * merge patch of the document of the person writes together (RFC 7396).
 */
final class AccountForm
{
    /**
     * Each member the form writes, in the order of its rows, by where it sits in the document of a person: the patch puts
     * it at the same place, an address member inside the address it is merged into.
     *
     * @var array<string, list<string>>
     */
    private const array MEMBERS = [
        'username' => ['username'],
        'email' => ['claims', 'email'],
        'email_verified' => ['claims', 'email_verified'],
        'phone_number' => ['claims', 'phone_number'],
        'phone_number_verified' => ['claims', 'phone_number_verified'],
        'name' => ['claims', 'name'],
        'given_name' => ['claims', 'given_name'],
        'family_name' => ['claims', 'family_name'],
        'middle_name' => ['claims', 'middle_name'],
        'nickname' => ['claims', 'nickname'],
        'preferred_username' => ['claims', 'preferred_username'],
        'profile' => ['claims', 'profile'],
        'picture' => ['claims', 'picture'],
        'website' => ['claims', 'website'],
        'gender' => ['claims', 'gender'],
        'birthdate' => ['claims', 'birthdate'],
        'zoneinfo' => ['claims', 'zoneinfo'],
        'locale' => ['claims', 'locale'],
        'address.formatted' => ['claims', 'address', 'formatted'],
        'address.street_address' => ['claims', 'address', 'street_address'],
        'address.locality' => ['claims', 'address', 'locality'],
        'address.region' => ['claims', 'address', 'region'],
        'address.postal_code' => ['claims', 'address', 'postal_code'],
        'address.country' => ['claims', 'address', 'country'],
    ];

    /**
     * The members that say whether an address or a number was verified, which Enter turns over rather than types.
     *
     * @var list<string>
     */
    private const array FLAGS = ['email_verified', 'phone_number_verified'];

    /**
     * @var array<string, string|bool|null> the value of each member changed and not saved yet, null taking it away
     */
    private array $changed = [];

    public function __construct(
        private readonly Document $person,
    ) {
    }

    /**
     * The name of the member of that row, null for a row the form does not have.
     */
    public function member(int $row): ?string
    {
        return array_keys(self::MEMBERS)[$row] ?? null;
    }

    public function isFlag(string $member): bool
    {
        return in_array($member, self::FLAGS, true);
    }

    /**
     * What the field of that member starts with: the value changed, or the one the person holds, and nothing for none.
     */
    public function text(string $member): string
    {
        $value = $this->value($member);

        return is_string($value) ? $value : '';
    }

    /**
     * A value typed for that member, trimmed, an empty one taking the member away: typed as the person holds it, it is
     * no change.
     */
    public function write(string $member, string $typed): void
    {
        $typed = trim($typed);
        $this->change($member, $typed === '' ? null : $typed);
    }

    /**
     * A yes for a no, and a no for a yes; a yes for a member the person does not hold.
     */
    public function turnOver(string $member): void
    {
        $this->change($member, $this->value($member) !== true);
    }

    public function isChanged(): bool
    {
        return $this->changed !== [];
    }

    /**
     * @return array<array-key, mixed> the members changed, each where the document of the person holds it
     */
    public function patch(): array
    {
        $patch = [];
        foreach (self::MEMBERS as $member => $path) {
            if (array_key_exists($member, $this->changed)) {
                $patch = self::placed($patch, $path, $this->changed[$member]);
            }
        }

        return $patch;
    }

    /**
     * @return list<list<string>> each member, the value the form holds for it, and whether that value is a change not saved yet
     */
    public function rows(SectionContext $context): array
    {
        $rows = [];
        foreach (array_keys(self::MEMBERS) as $member) {
            $value = $this->value($member);
            $rows[] = [
                $member,
                is_bool($value) ? $context->yesOrNo($value) : TerminalText::line($value ?? '-')->value,
                array_key_exists($member, $this->changed) ? $context->translate('account_form.changed') : '',
            ];
        }

        return $rows;
    }

    private function change(string $member, string|bool|null $value): void
    {
        if ($value === $this->held($member)) {
            unset($this->changed[$member]);

            return;
        }
        $this->changed[$member] = $value;
    }

    private function value(string $member): string|bool|null
    {
        return array_key_exists($member, $this->changed) ? $this->changed[$member] : $this->held($member);
    }

    /**
     * What the document of the person says of the member, and nothing for what is neither a string nor a boolean.
     */
    private function held(string $member): string|bool|null
    {
        $value = $this->person->members;
        foreach (self::MEMBERS[$member] ?? [] as $segment) {
            $value = is_array($value) ? $value[$segment] ?? null : null;
        }

        return is_string($value) || is_bool($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $document
     * @param list<string>            $path
     *
     * @return array<array-key, mixed>
     */
    private static function placed(array $document, array $path, string|bool|null $value): array
    {
        $segment = array_shift($path);
        if ($segment === null) {
            return $document;
        }
        if ($path === []) {
            $document[$segment] = $value;

            return $document;
        }
        $inner = $document[$segment] ?? [];
        $document[$segment] = self::placed(is_array($inner) ? $inner : [], $path, $value);

        return $document;
    }
}
