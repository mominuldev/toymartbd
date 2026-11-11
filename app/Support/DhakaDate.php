<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Report day-boundaries in Dhaka time (CLAUDE.md rule 11).
 *
 * Timestamps are stored UTC. A "day" in any report is a Dhaka day, so an order
 * placed at 23:30 UTC belongs to the NEXT day. Query a day with utcBounds(), which
 * keeps the WHERE clause on the raw indexed column instead of wrapping it in a
 * timezone conversion.
 */
final class DhakaDate
{
    public static function zone(): string
    {
        $zone = config('app.display_timezone', 'Asia/Dhaka');

        return is_string($zone) ? $zone : 'Asia/Dhaka';
    }

    /** The current Dhaka calendar date, as Y-m-d. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::zone())->toDateString();
    }

    /** The Dhaka calendar date an instant falls on, as Y-m-d. */
    public static function dayOf(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::zone())->toDateString();
    }

    /** An instant expressed in Dhaka time, for display. */
    public static function local(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::zone());
    }

    /**
     * The UTC half-open interval [start, end) covering a Dhaka calendar date:
     *   ->where('placed_at', '>=', $start)->where('placed_at', '<', $end)
     *
     * @param  string  $date  Y-m-d, a Dhaka date
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function utcBounds(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::zone());

        if ($start === null || $start->toDateString() !== $date) {
            throw new InvalidArgumentException("Not a Y-m-d date: [{$date}]");
        }

        return [$start->utc(), $start->addDay()->utc()];
    }
}
