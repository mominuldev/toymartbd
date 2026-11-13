<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

use App\Domain\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;

/**
 * How often a scheduled report goes out, and which Dhaka-date period each send covers:
 *
 *   daily    every day; covers the previous day
 *   weekly   every Saturday; covers the previous Saturday–Friday week (the shop's week,
 *            ReportPeriod::WEEK_STARTS_ON)
 *   monthly  on the 1st; covers the previous calendar month
 */
enum ReportFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * The latest send date (a Dhaka calendar date) on or before `$day`.
     */
    public function sendDateOnOrBefore(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $day,
            self::Weekly => $day->subDays(($day->dayOfWeek - CarbonImmutable::SATURDAY + 7) % 7),
            self::Monthly => $day->startOfMonth(),
        };
    }

    /** The send date before `$sendDate`. */
    public function previousSendDate(CarbonImmutable $sendDate): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $sendDate->subDay(),
            self::Weekly => $sendDate->subWeek(),
            self::Monthly => $sendDate->subMonthNoOverflow()->startOfMonth(),
        };
    }

    /** The period a send on `$sendDate` reports on — always complete days before it. */
    public function periodFor(CarbonImmutable $sendDate): DateRange
    {
        return match ($this) {
            self::Daily => DateRange::custom($sendDate->subDay()->toDateString(), $sendDate->subDay()->toDateString()),
            self::Weekly => DateRange::custom($sendDate->subDays(7)->toDateString(), $sendDate->subDay()->toDateString()),
            self::Monthly => DateRange::month($sendDate->subMonthNoOverflow()->year, $sendDate->subMonthNoOverflow()->month),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
