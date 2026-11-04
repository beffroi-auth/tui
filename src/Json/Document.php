<?php

declare(strict_types=1);

namespace Beffroi\Tui\Json;

use function array_is_list;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;
use JsonException;
use function sprintf;

/**
 * A JSON document the provider answered or this program wrote, read member by member: every reading says
 * what it expected, and a member that is not that is refused instead of coerced. A boolean is a JSON
 * boolean and never a string or a number read as one (decision 0006).
 *
 * It is the reading of the core's `Domain\Encoding\Document`, written again here because this program is a
 * client of the provider over HTTP and installs none of its packages (decision 0038).
 */
final readonly class Document
{
    /**
     * @param array<array-key, mixed> $members
     */
    public function __construct(
        public array $members = [],
    ) {
    }

    /**
     * @throws UnreadableDocumentException when the text is not JSON, or not a JSON object
     */
    public static function decode(string $json, string $named): self
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnreadableDocumentException(sprintf('The %s is not JSON.', $named), previous: $exception);
        }

        return self::of($value, $named);
    }

    /**
     * An empty JSON object decodes as an empty array, which is a list too: it is read as the object it was.
     *
     * @throws UnreadableDocumentException when the value is not a JSON object
     */
    public static function of(mixed $value, string $named): self
    {
        if (! is_array($value) || $value !== [] && array_is_list($value)) {
            throw new UnreadableDocumentException(sprintf('The %s must be a JSON object, got %s.', $named, get_debug_type($value)));
        }

        return new self($value);
    }

    /**
     * @throws UnreadableDocumentException when the member is absent, empty or not a string
     */
    public function string(string $name): string
    {
        $value = $this->nullableString($name);
        if ($value === null || $value === '') {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be a string that is not empty.', $name));
        }

        return $value;
    }

    /**
     * @throws UnreadableDocumentException when the member is there and is not a string
     */
    public function nullableString(string $name): ?string
    {
        $value = $this->members[$name] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be a string or absent.', $name));
        }

        return $value;
    }

    /**
     * @throws UnreadableDocumentException when the member is there and is not an integer
     */
    public function nullableInt(string $name): ?int
    {
        $value = $this->members[$name] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be an integer or absent.', $name));
        }

        return $value;
    }

    /**
     * Whether the member is the JSON boolean true: absent and false are both no.
     *
     * @throws UnreadableDocumentException when the member is there and is not a boolean
     */
    public function isTrue(string $name): bool
    {
        return $this->nullableBool($name) === true;
    }

    /**
     * The boolean of a member, and null when it is absent or null: what the document did not say, which is not a no.
     *
     * @throws UnreadableDocumentException when the member is there and is not a boolean
     */
    public function nullableBool(string $name): ?bool
    {
        $value = $this->members[$name] ?? null;
        if ($value !== null && ! is_bool($value)) {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be a boolean or absent.', $name));
        }

        return $value;
    }

    /**
     * The objects of a member that is a JSON list of objects, in their order, and none when it is absent.
     *
     * @throws UnreadableDocumentException when the member is there and is not a JSON list of objects
     *
     * @return list<self>
     */
    public function documents(string $name): array
    {
        $value = $this->members[$name] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be a JSON list.', $name));
        }
        $documents = [];
        foreach ($value as $index => $entry) {
            $documents[] = self::of($entry, sprintf('entry %d of the member "%s"', $index, $name));
        }

        return $documents;
    }

    /**
     * The strings of a member that is a JSON list of strings, in their order, and none when it is absent.
     *
     * @throws UnreadableDocumentException when the member is there and is not a JSON list of strings
     *
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $value = $this->members[$name] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnreadableDocumentException(sprintf('The member "%s" must be a JSON list.', $name));
        }
        $strings = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new UnreadableDocumentException(sprintf('The member "%s" must be a JSON list of strings.', $name));
            }
            $strings[] = $entry;
        }

        return $strings;
    }

    /**
     * @throws UnreadableDocumentException when the member is there and is not a JSON object
     */
    public function nested(string $name): ?self
    {
        $value = $this->members[$name] ?? null;

        return $value === null ? null : self::of($value, sprintf('member "%s"', $name));
    }
}
