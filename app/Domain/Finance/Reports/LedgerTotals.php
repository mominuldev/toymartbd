<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Services\OrderLedgerSources;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates over the append-only `financial_transactions` ledger
 * (DATABASE-DESIGN §3.7). Nothing here writes; reports never touch the ledger otherwise.
 *
 * `occurred_on` is already a Dhaka calendar date (rule 11) — the writer converts the
 * instant when it records the entry — so a day here is a Dhaka day by construction and
 * every query is a plain range on the `(occurred_on, type)` index.
 *
 * Every figure is the net effect on the business: Σ in − Σ out.
 */
final readonly class LedgerTotals
{
    public function __construct(private OrderLedgerSources $sources) {}

    /**
     * Recognised (P&L) totals per type over the period.
     *
     * @return array<string, Money> type => Σ in − Σ out
     */
    public function recognisedByType(ReportPeriod $period): array
    {
        $totals = [];
        foreach ($this->grouped($period, ['type'], recognised: true) as $row) {
            $totals[$row->type] = ($totals[$row->type] ?? Money::zero())->plus($this->signed($row));
        }

        return $totals;
    }

    /**
     * Recognised totals per Dhaka day and type — what a daily series is built from.
     *
     * @return array<string, array<string, Money>> Y-m-d => type => net
     */
    public function recognisedByDay(ReportPeriod $period): array
    {
        $days = [];
        foreach ($this->grouped($period, ['occurred_on', 'type'], recognised: true) as $row) {
            $day = substr((string) $row->occurred_on, 0, 10);
            $days[$day][$row->type] = ($days[$day][$row->type] ?? Money::zero())->plus($this->signed($row));
        }

        return $days;
    }

    /**
     * Recognised entries that carry an expense category, per category — the operating
     * expense breakdown.
     *
     * @return list<array{category_id: int, name: string, name_bn: string|null, type: string, parent_id: int|null, amount: Money}>
     */
    public function recognisedByCategory(ReportPeriod $period): array
    {
        $rows = $this->base($period)
            ->where('financial_transactions.is_recognised_revenue', true)
            ->whereNotNull('financial_transactions.category_id')
            ->join('expense_categories', 'expense_categories.id', '=', 'financial_transactions.category_id')
            ->groupBy('financial_transactions.category_id', 'financial_transactions.direction', 'expense_categories.name', 'expense_categories.name_bn', 'expense_categories.type', 'expense_categories.parent_id')
            ->select('financial_transactions.category_id', 'financial_transactions.direction', 'expense_categories.name', 'expense_categories.name_bn', 'expense_categories.type', 'expense_categories.parent_id')
            ->selectRaw('SUM(financial_transactions.amount) as total')
            ->get();

        $categories = [];
        foreach ($rows as $row) {
            $id = (int) $row->category_id;
            $categories[$id] ??= [
                'category_id' => $id,
                'name' => (string) $row->name,
                'name_bn' => $row->name_bn === null ? null : (string) $row->name_bn,
                'type' => (string) $row->type,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'amount' => Money::zero(),
            ];
            $categories[$id]['amount'] = $categories[$id]['amount']->plus($this->signed($row));
        }

        $list = array_values($categories);
        // Largest cost first (most negative effect on profit).
        usort($list, static fn (array $a, array $b): int => $a['amount']->compareTo($b['amount']) ?: $a['category_id'] <=> $b['category_id']);

        return $list;
    }

    /**
     * Cash movements (`is_cash_movement`) per type — the cash view, kept apart from the P&L.
     *
     * @return array<string, array{in: Money, out: Money}>
     */
    public function cashByType(ReportPeriod $period): array
    {
        $cash = [];
        foreach ($this->grouped($period, ['type'], cash: true) as $row) {
            $cash[$row->type] ??= ['in' => Money::zero(), 'out' => Money::zero()];
            $cash[$row->type][$row->direction === 'in' ? 'in' : 'out'] = $cash[$row->type][$row->direction === 'in' ? 'in' : 'out']->plus(ReportMoney::of($row->total));
        }
        ksort($cash);

        return $cash;
    }

    /**
     * Recognised net effect split by whether the entry belongs to an order — the
     * statement's reconciliation card ("sum of every order's profit" + "period costs not
     * charged to any order" = net profit). Order-scoped sources are OrderLedgerSources::MAP.
     *
     * @return array{orders: Money, other: Money, order_count: int}
     */
    public function orderSplit(ReportPeriod $period): array
    {
        // An entry is order-scoped when its source IS an order, or is a row (shipment,
        // refund, order-linked expense…) that carries an order id. An expense with no order
        // is a period cost, even though its source type can be order-linked.
        $cases = [];
        $bindings = [];
        foreach ($this->sources->mappings() as $sourceType => $target) {
            if ($target === null) {
                $cases[] = 'WHEN financial_transactions.source_type = ? THEN 1';
                $bindings[] = $sourceType;

                continue;
            }
            [$table, $column] = $target;
            $cases[] = "WHEN financial_transactions.source_type = ? AND EXISTS (SELECT 1 FROM {$table} WHERE {$table}.id = financial_transactions.source_id AND {$table}.{$column} IS NOT NULL) THEN 1";
            $bindings[] = $sourceType;
        }
        $case = $cases === [] ? '0' : 'CASE '.implode(' ', $cases).' ELSE 0 END';

        $orders = Money::zero();
        $other = Money::zero();
        $rows = DB::query()->fromSub(
            $this->base($period)
                ->where('financial_transactions.is_recognised_revenue', true)
                ->whereIn('financial_transactions.type', PnlLayout::pnlTypeValues())
                ->select('financial_transactions.direction', 'financial_transactions.amount')
                ->selectRaw("{$case} as is_order", $bindings),
            'scoped',
        )
            ->groupBy('is_order', 'direction')
            ->select('is_order', 'direction')
            ->selectRaw('SUM(amount) as total')
            ->get();
        foreach ($rows as $row) {
            if ((int) $row->is_order === 1) {
                $orders = $orders->plus($this->signed($row));
            } else {
                $other = $other->plus($this->signed($row));
            }
        }

        $count = (int) $this->base($period)
            ->where('financial_transactions.is_recognised_revenue', true)
            ->where('financial_transactions.source_type', OrderLedgerSources::ORDER)
            ->distinct()
            ->count('financial_transactions.source_id');

        return ['orders' => $orders, 'other' => $other, 'order_count' => $count];
    }

    /**
     * @param  list<string>  $by
     * @return iterable<object{type: string, direction: string, total: mixed, occurred_on?: mixed}>
     */
    private function grouped(ReportPeriod $period, array $by, bool $recognised = false, bool $cash = false): iterable
    {
        $columns = array_map(static fn (string $c): string => "financial_transactions.{$c}", [...$by, 'direction']);

        /** @var iterable<object{type: string, direction: string, total: mixed, occurred_on?: mixed}> */
        return $this->base($period)
            ->when($recognised, static fn (Builder $q) => $q->where('financial_transactions.is_recognised_revenue', true))
            ->when($cash, static fn (Builder $q) => $q->where('financial_transactions.is_cash_movement', true))
            ->select($columns)
            ->selectRaw('SUM(financial_transactions.amount) as total')
            ->groupBy($columns)
            ->get();
    }

    private function base(ReportPeriod $period): Builder
    {
        return DB::table('financial_transactions')
            ->where('financial_transactions.occurred_on', '>=', $period->fromDate())
            ->where('financial_transactions.occurred_on', '<=', $period->toDate());
    }

    private function signed(object $row): Money
    {
        $amount = ReportMoney::of($row->total ?? null);

        return ($row->direction ?? 'out') === 'in' ? $amount : $amount->negate();
    }
}
