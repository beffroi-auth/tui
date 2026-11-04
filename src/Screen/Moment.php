<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * An instant as the screen shows it: the date and the time in UTC, said as UTC, whatever the zone of the machine
 * the program runs on and of the provider it reads, so that two administrators read the same thing.
 */
final readonly class Moment
{
    public static function of(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C');
    }

    /**
     * The date and the minute in UTC, which a column of a table names as UTC once.
     */
    public static function short(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    }

    /**
     * The time of the day in UTC, said as UTC.
     */
    public static function time(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format('H:i:s \U\T\C');
    }

    /**
     * A number of seconds since 1970-01-01T00:00:00Z, which is how a JWT and OIDC Core §5.1 write an instant.
     */
    public static function ofSeconds(int $seconds): string
    {
        return self::of(new DateTimeImmutable('@' . $seconds));
    }
}
