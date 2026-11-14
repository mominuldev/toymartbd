<?php

declare(strict_types=1);

namespace App\Domain\Pos\Queries;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Pos\Models\PosSession;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a drawer session took, from the payments its sales wrote (never from a running
 * counter that could drift): sales count, Σ per tender method, and the cash the drawer
 * should hold — opening float + cash kept (cash payments are recorded net of change).
 */
final readonly class DrawerTotals
{
    /**
     * @return array{sales_count: int, sales_total: Money, by_method: array<string, Money>, expected_cash: Money}
     */
    public function for(PosSession $session): array
    {
        $rows = DB::table('payments as p')
            ->join('pos_sales as s', 's.order_id', '=', 'p.order_id')
            ->where('s.session_id', $session->id)
            ->where('p.status', PaymentStatus::Paid->value)
            ->groupBy('p.method')
            ->select('p.method')
            ->selectRaw('SUM(p.amount) as total')
            ->get();

        $byMethod = [];
        $total = Money::zero();
        foreach ($rows as $row) {
            $amount = self::money($row->total);
            $byMethod[(string) $row->method] = $amount;
            $total = $total->plus($amount);
        }

        return [
            'sales_count' => DB::table('pos_sales')->where('session_id', $session->id)->count(),
            'sales_total' => $total,
            'by_method' => $byMethod,
            'expected_cash' => $session->opening_float->plus($byMethod['cash'] ?? Money::zero()),
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
