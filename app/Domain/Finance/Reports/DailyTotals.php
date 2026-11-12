<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Recognised per-type totals for a period, from the cheapest correct source.
 *
 * A short range (≤ LONG_RANGE_DAYS) always reads the ledger — it is exact to the minute
 * and the `(occurred_on, type)` index makes it cheap. A long range reads the nightly
 * `daily_financial_summaries` rollup for every day it covers and the ledger only for the
 * days after the last rollup (today, and any night the job has not run yet), so a
 * 12-month chart never scans a year of ledger rows (DATABASE-DESIGN §3.7).
 */
final readonly class DailyTotals
{
    public const int LONG_RANGE_DAYS = 92;

    public function __construct(private LedgerTotals $ledger, private SummaryTotals $summaries) {}

    /** @return array<string, Money> type => net */
    public function byType(ReportPeriod $period): array
    {
        $totals = [];
        foreach ($this->byDay($period) as $types) {
            foreach ($types as $type => $amount) {
                $totals[$type] = ($totals[$type] ?? Money::zero())->plus($amount);
            }
        }

        return $totals;
    }

    /** @return array<string, array<string, Money>> Y-m-d => type => net */
    public function byDay(ReportPeriod $period): array
    {
        $split = $this->split($period);
        if ($split === null) {
            return $this->ledger->recognisedByDay($period);
        }

        [$rolled, $rest] = $split;
        $days = $this->summaries->byDay($rolled);
        if ($rest !== null) {
            $days += $this->ledger->recognisedByDay($rest);
        }
        ksort($days);

        return $days;
    }

    /** `ledger`, `summaries`, or `mixed` (summaries plus the ledger for the newest days). */
    public function sourceFor(ReportPeriod $period): string
    {
        $split = $this->split($period);

        return match (true) {
            $split === null => 'ledger',
            $split[1] === null => 'summaries',
            default => 'mixed',
        };
    }

    /** @return array{0: ReportPeriod, 1: ReportPeriod|null}|null [rolled-up part, ledger part] */
    private function split(ReportPeriod $period): ?array
    {
        if ($period->days() <= self::LONG_RANGE_DAYS) {
            return null;
        }
        $last = $this->summaries->lastSummarisedDate();
        if ($last === null || $last < $period->fromDate()) {
            return null;
        }
        if ($last >= $period->toDate()) {
            return [$period, null];
        }

        $next = CarbonImmutable::parse($last, 'UTC')->addDay()->toDateString();

        return [ReportPeriod::of($period->fromDate(), $last), ReportPeriod::of($next, $period->toDate())];
    }
}
