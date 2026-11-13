<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The date range every Reporting query (dashboard, sales, customer analytics, scheduled
 * reports) runs over: Dhaka calendar days (rule 11), inclusive at both ends, plus the preset
 * that produced it and the range it compares against.
 *
 * It wraps {@see ReportPeriod} — the finance reports' period — rather than duplicating it,
 * so week bucketing (Saturday–Friday, owner decision 2026-11-12), the UTC bounds of a range
 * and the length cap stay in one place. Convert with {@see self::period()} and
 * {@see self::fromPeriod()} to call `Finance\Reports\*` for any ledger figure.
 *
 * Presets:
 *   today       today only
 *   7d          the last 7 Dhaka days, today included
 *   30d         the last 30 Dhaka days, today included
 *   this_month  the 1st of this month to today (month to date)
 *   last_month  the whole previous calendar month
 *   custom      explicit from/to (Y-m-d)
 *
 * Comparison ({@see self::previous()}): a month preset compares against the same days of
 * the month before (this_month 1–13 Nov → 1–13 Oct, clipped to that month's length;
 * last_month → the whole month before it). Every other range compares against the same
 * number of days immediately before it.
 *
 * Bad input throws InvalidArgumentException; the HTTP layer turns it into a 400.
 */
final readonly class DateRange
{
    public const string TODAY = 'today';

    public const string LAST_7_DAYS = '7d';

    public const string LAST_30_DAYS = '30d';

    public const string THIS_MONTH = 'this_month';

    public const string LAST_MONTH = 'last_month';

    public const string CUSTOM = 'custom';

    public const string DEFAULT_PRESET = self::LAST_7_DAYS;

    private function __construct(public ReportPeriod $period, public string $preset) {}

    /** @return list<string> */
    public static function presets(): array
    {
        return [self::TODAY, self::LAST_7_DAYS, self::LAST_30_DAYS, self::THIS_MONTH, self::LAST_MONTH, self::CUSTOM];
    }

    /**
     * Resolve a preset against today's Dhaka date. `custom` requires both dates; any other
     * preset rejects them (an ambiguous request is a 400, not a guess).
     *
     * @param  string|null  $from  Y-m-d, custom only
     * @param  string|null  $to  Y-m-d, custom only
     */
    public static function fromPreset(?string $preset, ?string $from = null, ?string $to = null): self
    {
        $preset ??= ($from !== null || $to !== null) ? self::CUSTOM : self::DEFAULT_PRESET;

        if (! in_array($preset, self::presets(), true)) {
            throw new InvalidArgumentException('range must be one of: '.implode(', ', self::presets()).'.');
        }

        if ($preset === self::CUSTOM) {
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('A custom range needs both from and to (YYYY-MM-DD).');
            }

            return self::custom($from, $to);
        }

        if ($from !== null || $to !== null) {
            throw new InvalidArgumentException("from/to are only accepted with range=custom, not range={$preset}.");
        }

        $today = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::today(), 'UTC');
        assert($today instanceof CarbonImmutable);

        [$start, $end] = match ($preset) {
            self::TODAY => [$today, $today],
            self::LAST_7_DAYS => [$today->subDays(6), $today],
            self::LAST_30_DAYS => [$today->subDays(29), $today],
            self::THIS_MONTH => [$today->startOfMonth(), $today],
            self::LAST_MONTH => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            default => throw new InvalidArgumentException("Unhandled preset [{$preset}]."),
        };

        return new self(ReportPeriod::of($start->toDateString(), $end->toDateString()), $preset);
    }

    /**
     * @param  string  $from  Y-m-d, inclusive
     * @param  string  $to  Y-m-d, inclusive
     */
    public static function custom(string $from, string $to): self
    {
        return new self(ReportPeriod::of($from, $to), self::CUSTOM);
    }

    /** Wrap a finance ReportPeriod (preset `custom`). */
    public static function fromPeriod(ReportPeriod $period): self
    {
        return new self($period, self::CUSTOM);
    }

    /** One whole Dhaka calendar month, e.g. for a monthly scheduled report. */
    public static function month(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
        if (! $start instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Not a month: {$year}-{$month}");
        }

        return self::custom($start->toDateString(), $start->endOfMonth()->toDateString());
    }

    /** The finance period, for `Finance\Reports\*` (ProfitAndLoss, DailyTotals, LedgerTotals …). */
    public function period(): ReportPeriod
    {
        return $this->period;
    }

    public function fromDate(): string
    {
        return $this->period->fromDate();
    }

    public function toDate(): string
    {
        return $this->period->toDate();
    }

    public function days(): int
    {
        return $this->period->days();
    }

    public function includesToday(): bool
    {
        $today = DhakaDate::today();

        return $this->fromDate() <= $today && $today <= $this->toDate();
    }

    /** The comparison range (see the class doc). Its preset is always `custom`. */
    public function previous(): self
    {
        if ($this->preset === self::THIS_MONTH || $this->preset === self::LAST_MONTH) {
            $from = $this->period->from;
            $start = $from->startOfMonth()->subMonthNoOverflow();
            $lastOfThatMonth = $start->endOfMonth()->startOfDay();
            $end = $start->addDays($this->days() - 1);
            if ($this->preset === self::LAST_MONTH || $end->greaterThan($lastOfThatMonth)) {
                $end = $lastOfThatMonth;
            }

            return self::custom($start->toDateString(), $end->toDateString());
        }

        return new self($this->period->previous(), self::CUSTOM);
    }

    /**
     * UTC [start, end) for querying a timestamp column (orders.placed_at …).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return $this->period->utcBounds();
    }

    /**
     * @return list<array{key: string, from: string, to: string, days: int}>
     */
    public function buckets(ReportGranularity $granularity): array
    {
        return $this->period->buckets($granularity);
    }

    public function bucketOf(string $day, ReportGranularity $granularity): string
    {
        return $this->period->bucketOf($day, $granularity);
    }

    /** @return array{preset: string, from: string, to: string, days: int} */
    public function toArray(): array
    {
        return ['preset' => $this->preset, 'from' => $this->fromDate(), 'to' => $this->toDate(), 'days' => $this->days()];
    }
}
