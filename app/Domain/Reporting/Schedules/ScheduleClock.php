<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Schedules;

use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Support\DateRange;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

/**
 * When a schedule is due. The latest occurrence (send date at `send_time`, Asia/Dhaka) that
 * is at or before now is the one to send; its period is complete Dhaka days before the send
 * date ({@see \App\Domain\Reporting\Enums\ReportFrequency::periodFor()}). An occurrence from
 * before the schedule existed is never sent (no surprise backfill when a schedule is
 * created at 10:00 with an 08:00 send time). If the sweep was down, the latest missed
 * occurrence goes out on the next sweep — once, because the run table is keyed on the period.
 */
final class ScheduleClock
{
    /** @return array{due_at: CarbonImmutable, range: DateRange}|null due_at in UTC */
    public static function due(ReportSchedule $schedule, CarbonImmutable $now): ?array
    {
        $occurrence = self::latestOccurrence($schedule, $now);
        $created = $schedule->created_at === null ? null : CarbonImmutable::instance($schedule->created_at);
        if ($created !== null && $occurrence->lessThan($created)) {
            return null;
        }

        $sendDate = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::dayOf($occurrence), 'UTC');
        assert($sendDate instanceof CarbonImmutable);

        return ['due_at' => $occurrence, 'range' => $schedule->frequency->periodFor($sendDate)];
    }

    /** The next send instant after now (UTC), for display. */
    public static function next(ReportSchedule $schedule, CarbonImmutable $now): CarbonImmutable
    {
        $zone = DhakaDate::zone();
        $today = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::dayOf($now), 'UTC');
        assert($today instanceof CarbonImmutable);
        // Walk forward from the latest occurrence until it is in the future.
        $date = $schedule->frequency->sendDateOnOrBefore($today);
        for ($i = 0; $i < 40; $i++) {
            $at = self::at($date, $schedule->send_time, $zone);
            if ($at->greaterThan($now)) {
                return $at;
            }
            $date = match ($schedule->frequency->value) {
                'daily' => $date->addDay(),
                'weekly' => $date->addWeek(),
                default => $date->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return self::at($date, $schedule->send_time, $zone);
    }

    private static function latestOccurrence(ReportSchedule $schedule, CarbonImmutable $now): CarbonImmutable
    {
        $zone = DhakaDate::zone();
        $today = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::dayOf($now), 'UTC');
        assert($today instanceof CarbonImmutable);
        $date = $schedule->frequency->sendDateOnOrBefore($today);
        $at = self::at($date, $schedule->send_time, $zone);
        if ($at->greaterThan($now)) {
            $at = self::at($schedule->frequency->previousSendDate($date), $schedule->send_time, $zone);
        }

        return $at;
    }

    /** A Dhaka send date + HH:MM as a UTC instant. */
    private static function at(CarbonImmutable $date, string $time, string $zone): CarbonImmutable
    {
        $instant = CarbonImmutable::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$time, $zone);
        assert($instant instanceof CarbonImmutable);

        return $instant->utc();
    }
}
