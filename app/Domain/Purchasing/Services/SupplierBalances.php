<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payables (PRD N-10): what we owe each supplier.
 *
 *     balance = opening_balance + Σ receipts' payable_amount − Σ payments
 *
 * A PO only becomes payable as goods arrive (a receipt), never when it is merely
 * ordered. A negative balance is an advance paid ahead of delivery.
 */
final class SupplierBalances
{
    /** @return array{opening: Money, received: Money, paid: Money, balance: Money} */
    public function for(Supplier $supplier): array
    {
        return $this->forMany([$supplier])[$supplier->id];
    }

    /**
     * @param  iterable<Supplier>  $suppliers
     * @return array<int, array{opening: Money, received: Money, paid: Money, balance: Money}>
     */
    public function forMany(iterable $suppliers): array
    {
        $byId = [];
        foreach ($suppliers as $supplier) {
            $byId[$supplier->id] = $supplier;
        }
        if ($byId === []) {
            return [];
        }
        $ids = array_keys($byId);

        $received = DB::table('purchase_receipts')
            ->join('purchases', 'purchases.id', '=', 'purchase_receipts.purchase_id')
            ->whereIn('purchases.supplier_id', $ids)
            ->groupBy('purchases.supplier_id')
            ->selectRaw('purchases.supplier_id as supplier_id, SUM(purchase_receipts.payable_amount) as total')
            ->pluck('total', 'supplier_id');
        $paid = DB::table('supplier_payments')
            ->whereIn('supplier_id', $ids)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->pluck('total', 'supplier_id');

        $out = [];
        foreach ($byId as $id => $supplier) {
            $in = Money::of(self::decimal($received[$id] ?? null));
            $out_ = Money::of(self::decimal($paid[$id] ?? null));
            $out[$id] = [
                'opening' => $supplier->opening_balance,
                'received' => $in,
                'paid' => $out_,
                'balance' => $supplier->opening_balance->plus($in)->minus($out_),
            ];
        }

        return $out;
    }

    private static function decimal(mixed $value): string
    {
        return is_numeric($value) ? bcadd((string) $value, '0', 2) : '0';
    }
}
