<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the nightly `daily_financial_summaries` rollup (DATABASE-DESIGN §3.7): one row per
 * Dhaka `summary_date` with the recognised ledger types aggregated.
 *
 * Column contract (DailySummaryRollup): one column per ledger type (`sale_revenue`, `cogs`,
 * …) holding that type's day total **in the type's natural direction** — revenue as a
 * positive inflow, a cost as a positive outflow, a reversal netted against it. This class
 * turns it back into the effect on profit: natural `in` types as is, natural `out` types
 * negated. Assumption, asserted by the reports test: every entry of a P&L type is a
 * recognised one (the rollup's type columns do not filter on `is_recognised_revenue`).
 * A type column the table does not have reads as zero. When the table does not exist yet,
 * there is no rollup and DailyTotals reads the ledger for everything.
 */
final class SummaryTotals
{
    public const string TABLE = 'daily_financial_summaries';

    private ?bool $exists = null;

    /** @var list<string>|null */
    private ?array $columns = null;

    /** The newest day the rollup has written, or null when it has none. */
    public function lastSummarisedDate(): ?string
    {
        if (! $this->exists()) {
            return null;
        }
        $last = DB::table(self::TABLE)->max('summary_date');

        return is_scalar($last) ? substr((string) $last, 0, 10) : null;
    }

    /** @return array<string, array<string, Money>> Y-m-d => type => net */
    public function byDay(ReportPeriod $period): array
    {
        if (! $this->exists()) {
            return [];
        }
        $types = array_values(array_intersect(PnlLayout::pnlTypeValues(), $this->columns()));
        $rows = DB::table(self::TABLE)
            ->where('summary_date', '>=', $period->fromDate())
            ->where('summary_date', '<=', $period->toDate())
            ->get(['summary_date', ...$types]);

        $days = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->summary_date, 0, 10);
            foreach ($types as $type) {
                $amount = ReportMoney::of($row->{$type} ?? null);
                if (LedgerEntryType::from($type)->naturalDirection() === Direction::Out) {
                    $amount = $amount->negate();
                }
                if (! $amount->isZero()) {
                    $days[$day][$type] = $amount;
                }
            }
            $days[$day] ??= [];
        }

        return $days;
    }

    private function exists(): bool
    {
        return $this->exists ??= Schema::hasTable(self::TABLE);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return $this->columns ??= Schema::getColumnListing(self::TABLE);
    }
}
