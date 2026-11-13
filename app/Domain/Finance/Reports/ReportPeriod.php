<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An inclusive range of Dhaka calendar days (rule 11), and the bucketing a report series
 * uses over it.
 *
 * **Weeks start on Saturday.** The shop trades Saturday to Thursday and closes Friday
 * (05-content-and-assets-spec `footer.contact.hours`), so a Saturday–Friday week keeps one
 * trading week in one bucket. The national weekend is Friday + Saturday (government week
 * Sunday–Thursday); we follow the shop's own week, not the civil-service one. Change
 * WEEK_STARTS_ON to move it — nothing else assumes a weekday. Confirmed by the owner
 * 2026-11-12.
 *
 * A bucket is labelled by its first day (Y-m-d) and clipped to the range, so a "week" at
 * either end of a custom range may hold fewer than seven days; `days` says how many.
 */
final readonly class ReportPeriod
{
    /** Carbon weekday number: 6 = Saturday. */
    public const int WEEK_STARTS_ON = CarbonImmutable::SATURDAY;

    /** The longest range any report accepts. */
    public const int MAX_DAYS = 3660;

    private function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    /**
     * @param  string  $from  Y-m-d, inclusive
     * @param  string  $to  Y-m-d, inclusive
     */
    public static function of(string $from, string $to): self
    {
        $start = self::date($from);
        $end = self::date($to);

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('The period is longer than '.self::MAX_DAYS.' days.');
        }

        return new self($start, $end);
    }

    /** The default: the last seven Dhaka days, today included (04-admin-spec B7 "Last 7 days"). */
    public static function lastSevenDays(): self
    {
        $today = self::date(DhakaDate::today());

        return new self($today->subDays(6), $today);
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The same number of days immediately before this period. */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->subDays($days), $this->from->subDay());
    }

    /** The same dates one year earlier. */
    public function previousYear(): self
    {
        return new self($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow());
    }

    /**
     * UTC [start, end) for querying a timestamp column (orders.delivered_at …).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return [DhakaDate::utcBounds($this->fromDate())[0], DhakaDate::utcBounds($this->toDate())[1]];
    }

    /** The bucket a Dhaka day (Y-m-d) belongs to, as the bucket's first day, clipped to the range. */
    public function bucketOf(string $day, ReportGranularity $granularity): string
    {
        $date = self::date($day);
        $start = match ($granularity) {
            ReportGranularity::Day => $date,
            ReportGranularity::Week => $date->subDays(($date->dayOfWeek - self::WEEK_STARTS_ON + 7) % 7),
            ReportGranularity::Month => $date->startOfMonth(),
            ReportGranularity::Year => $date->startOfYear(),
        };

        return ($start->lessThan($this->from) ? $this->from : $start)->toDateString();
    }

    /**
     * Every bucket in the range, in order, with its clipped first and last day.
     *
     * @return list<array{key: string, from: string, to: string, days: int}>
     */
    public function buckets(ReportGranularity $granularity): array
    {
        $buckets = [];
        $cursor = $this->from;

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $next = match ($granularity) {
                ReportGranularity::Day => $cursor->addDay(),
                ReportGranularity::Week => $cursor->addDays(7 - (($cursor->dayOfWeek - self::WEEK_STARTS_ON + 7) % 7)),
                ReportGranularity::Month => $cursor->startOfMonth()->addMonthNoOverflow(),
                ReportGranularity::Year => $cursor->startOfYear()->addYear(),
            };
            $last = $next->subDay()->greaterThan($this->to) ? $this->to : $next->subDay();
            $buckets[] = ['key' => $cursor->toDateString(), 'from' => $cursor->toDateString(), 'to' => $last->toDateString(), 'days' => (int) $cursor->diffInDays($last) + 1];
            $cursor = $next;
        }

        return $buckets;
    }

    private static function date(string $value): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a Y-m-d date: [{$value}]");
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if ($date === null || $date->toDateString() !== $value) {
            throw new InvalidArgumentException("Not a real calendar date: [{$value}]");
        }

        return $date;
    }
}
