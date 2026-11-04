<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use function array_is_list;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A value of any JSON type the administration API answered where it says what a client registered or what is set of a
 * person, shown on one line: a string as it is, a list of strings and numbers joined by commas, anything else as the
 * JSON it was. It is the instance's text, or a third party's, and reaches the terminal through `TerminalText`.
 */
final readonly class JsonText
{
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value) && array_is_list($value)) {
            $entries = self::entries($value);
            if ($entries !== null) {
                return implode(', ', $entries);
            }
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * @param list<mixed> $list
     *
     * @return list<string>|null null when an entry is not a string or a number
     */
    private static function entries(array $list): ?array
    {
        $entries = [];
        foreach ($list as $entry) {
            if (! is_string($entry) && ! is_int($entry) && ! is_float($entry)) {
                return null;
            }
            $entries[] = (string) $entry;
        }

        return $entries;
    }
}
