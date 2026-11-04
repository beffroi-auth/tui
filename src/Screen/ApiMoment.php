<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use DateTimeImmutable;
use function preg_match;

/**
 * An instant the administration API wrote, an RFC 3339 date and time (§5.6), shown as every instant of the screen is
 * (`Moment`). A value that is not one is shown as it was written, a value the API did not have as a dash: what the
 * API writes is read for what it is, never as one of the relative dates PHP would also read ("tomorrow").
 */
final readonly class ApiMoment
{
    private const string DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/iD';

    public static function of(?string $written): string
    {
        $moment = self::read($written);

        return $moment === null ? $written ?? '-' : Moment::of($moment);
    }

    /**
     * The date and the minute in UTC, for a column of a table.
     */
    public static function short(?string $written): string
    {
        $moment = self::read($written);

        return $moment === null ? $written ?? '-' : Moment::short($moment);
    }

    private static function read(?string $written): ?DateTimeImmutable
    {
        if ($written === null || preg_match(self::DATE_TIME, $written) !== 1) {
            return null;
        }
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $written);
        if ($moment === false) {
            $moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $written);
        }

        return $moment === false ? null : $moment;
    }
}
