<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds `daily_financial_summaries` rows (DATABASE-DESIGN §3.7) for Dhaka dates. Every
 * figure is recomputed from source — the ledger (bucketed by its `occurred_on`, already a
 * Dhaka date) and the order/customer timestamps (bucketed through {@see DhakaDate::utcBounds()},
 * so an order at 23:30 UTC lands on the next Dhaka day, rule 11). Re-running a date
 * overwrites its row with the same answer; nothing accumulates.
 */
final readonly class DailySummaryRollup
{
    public function __construct(private Receivables $receivables) {}

    /** Roll up every Dhaka date in [from, to]; returns the number of rows written. */
    public function run(string $from, string $to): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === null || $end === null || $start->toDateString() !== $from || $end->toDateString() !== $to || $start->greaterThan($end)) {
            throw new InvalidArgumentException("Not a valid Y-m-d range: [{$from}, {$to}]");
        }

        $rows = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $rows[] = $this->row($day->toDateString());
        }

        $columns = array_keys($rows[0]);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('daily_financial_summaries')->upsert($chunk, ['summary_date'], array_values(array_diff($columns, ['summary_date'])));
        }

        return count($rows);
    }

    /**
     * The summary row for one Dhaka date.
     *
     * @return array<string, mixed>
     */
    public function row(string $date): array
    {
        $byType = [];
        foreach (LedgerEntryType::cases() as $type) {
            $byType[$type->value] = Money::zero();
        }
        $recognisedIn = $recognisedOut = $cashIn = $cashOut = Money::zero();

        $ledger = DB::table('financial_transactions')
            ->where('occurred_on', $date)
            ->groupBy('type', 'direction', 'is_recognised_revenue', 'is_cash_movement')
            ->select('type', 'direction', 'is_recognised_revenue', 'is_cash_movement')
            ->selectRaw('SUM(amount) as total')
            ->get();

        foreach ($ledger as $row) {
            $type = LedgerEntryType::tryFrom((string) $row->type);
            if ($type === null) {
                continue;
            }
            $amount = self::money($row->total);
            $in = $row->direction === Direction::In->value;
            $byType[$type->value] = $byType[$type->value]->plus(($in === ($type->naturalDirection() === Direction::In)) ? $amount : $amount->negate());

            if ((bool) $row->is_recognised_revenue) {
                $in ? $recognisedIn = $recognisedIn->plus($amount) : $recognisedOut = $recognisedOut->plus($amount);
            }
            if ((bool) $row->is_cash_movement) {
                $in ? $cashIn = $cashIn->plus($amount) : $cashOut = $cashOut->plus($amount);
            }
        }

        [$startUtc, $endUtc] = DhakaDate::utcBounds($date);
        $count = static fn (string $table, string $column): int => DB::table($table)
            ->where($column, '>=', $startUtc)->where($column, '<', $endUtc)->count();

        $unitsSold = (int) DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.delivered_at', '>=', $startUtc)->where('o.delivered_at', '<', $endUtc)
            ->sum('i.quantity');

        return [
            'summary_date' => $date,
            ...array_map(static fn (Money $m): string => $m->toDecimal(), $byType),
            'recognised_in' => $recognisedIn->toDecimal(),
            'recognised_out' => $recognisedOut->toDecimal(),
            'net_profit' => $recognisedIn->minus($recognisedOut)->toDecimal(),
            'cash_in' => $cashIn->toDecimal(),
            'cash_out' => $cashOut->toDecimal(),
            'net_cash' => $cashIn->minus($cashOut)->toDecimal(),
            'orders_placed' => $count('orders', 'placed_at'),
            'orders_delivered' => $count('orders', 'delivered_at'),
            'orders_cancelled' => $count('orders', 'cancelled_at'),
            'orders_returned' => $count('orders', 'returned_at'),
            'new_customers' => $count('customers', 'created_at'),
            'units_sold' => $unitsSold,
            'cod_receivable_closing' => $this->receivables->total($date)->toDecimal(),
            'computed_at' => DhakaDate::instant(),
        ];
    }

    private static function money(mixed $value): Money
    {
        return match (true) {
            is_float($value) => Money::of(number_format($value, 2, '.', '')),
            is_int($value), is_string($value) => Money::of($value),
            default => Money::zero(),
        };
    }
}
