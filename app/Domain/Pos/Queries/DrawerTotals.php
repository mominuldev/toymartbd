<?php

declare(strict_types=1);

namespace App\Domain\Pos\Queries;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Pos\Models\PosSession;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a drawer session took, from the payments it wrote (never from a running counter that
 * could drift):
 *
 *   - a sale's own tenders: paid payments on the orders its pos_sales created, EXCLUDING any
 *     payment a due collection later wrote on the same order (that money belongs to the drawer
 *     that collected it, which may be another one);
 *   - due collected on this drawer: the payments its pos_due_collections allocated.
 *
 * `by_method` and `expected_cash` (opening float + cash kept, cash recorded net of change)
 * include both. `sales_total` is the sales' own paid tenders; `due_sold` is what sales put on
 * due (no money, so never in expected cash); `due_collected` is Σ collections.
 */
final readonly class DrawerTotals
{
    /**
     * @return array{sales_count: int, sales_total: Money, by_method: array<string, Money>, expected_cash: Money, due_sold: Money, due_collected: Money, due_collections_count: int}
     */
    public function for(PosSession $session): array
    {
        $sales = DB::table('payments as p')
            ->join('pos_sales as s', 's.order_id', '=', 'p.order_id')
            ->where('s.session_id', $session->id)
            ->where('p.status', PaymentStatus::Paid->value)
            ->whereNotIn('p.id', DB::table('pos_due_collection_allocations')->select('payment_id'))
            ->groupBy('p.method')
            ->select('p.method')
            ->selectRaw('SUM(p.amount) as total')
            ->get();

        $collected = DB::table('payments as p')
            ->join('pos_due_collection_allocations as a', 'a.payment_id', '=', 'p.id')
            ->join('pos_due_collections as c', 'c.id', '=', 'a.collection_id')
            ->where('c.session_id', $session->id)
            ->where('p.status', PaymentStatus::Paid->value)
            ->groupBy('p.method')
            ->select('p.method')
            ->selectRaw('SUM(p.amount) as total')
            ->get();

        $byMethod = [];
        $salesTotal = Money::zero();
        foreach ($sales as $row) {
            $amount = self::money($row->total);
            $byMethod[(string) $row->method] = ($byMethod[(string) $row->method] ?? Money::zero())->plus($amount);
            $salesTotal = $salesTotal->plus($amount);
        }
        $dueCollected = Money::zero();
        foreach ($collected as $row) {
            $amount = self::money($row->total);
            $byMethod[(string) $row->method] = ($byMethod[(string) $row->method] ?? Money::zero())->plus($amount);
            $dueCollected = $dueCollected->plus($amount);
        }

        return [
            'sales_count' => DB::table('pos_sales')->where('session_id', $session->id)->count(),
            'sales_total' => $salesTotal,
            'by_method' => $byMethod,
            'expected_cash' => $session->opening_float->plus($byMethod['cash'] ?? Money::zero()),
            'due_sold' => self::money(DB::table('pos_sales')->where('session_id', $session->id)->sum('due_amount')),
            'due_collected' => $dueCollected,
            'due_collections_count' => DB::table('pos_due_collections')->where('session_id', $session->id)->count(),
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
