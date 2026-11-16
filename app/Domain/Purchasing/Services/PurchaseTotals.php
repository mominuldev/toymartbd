<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Models\Purchase;
use App\Support\Money;

/**
 * PO money, computed only here (rule 1):
 *   line gross  = quantity × unit_cost, to the poisha
 *   line_total  = line gross − line discount
 *   total       = subtotal − discount + shipping_cost + customs_duty + other_cost + tax
 *   due_amount  = received_value − paid_amount
 *
 * Presentation (owner decision 2026-11-16): a PO's order total is what was ordered — goods
 * only. Freight, customs and other costs entered at receipt live on the receipts; the
 * supplier is owed them only when it charged them (`costs_paid_to_supplier`), which
 * `received_value` (Σ receipts' payable_amount) already reflects. Before that decision a
 * receipt also added its extras to the PO header (shipping_cost / customs_duty / other_cost
 * and so `total`); `ordered()` and `extras()` read every PO the same way, old or new,
 * without rewriting a stored row.
 *
 * @internal for the Purchasing actions and the admin's PO resources
 */
final class PurchaseTotals
{
    public static function lineGross(PurchaseLineData $line): Money
    {
        return Money::of(Decimals::round(bcmul((string) $line->quantity, $line->unitCost, 8), 2));
    }

    public static function lineTotal(PurchaseLineData $line): Money
    {
        return self::lineGross($line)->minus($line->discount);
    }

    /** What was ordered: goods less line discounts, plus tax — never a receipt's extra costs. */
    public static function ordered(Purchase $purchase): Money
    {
        return $purchase->subtotal->minus($purchase->discount)->plus($purchase->tax);
    }

    /** The same, from a row's raw columns (the KPI sum over many POs), as a SQL expression. */
    public const string ORDERED_SQL = 'subtotal - discount + tax';

    /**
     * Extra costs entered at receipt, summed from the PO's receipts (load `receipts` first):
     * each kind, their total, and the part the supplier charged (owed to it) vs paid to someone else.
     *
     * @return array{freight: Money, customs_duty: Money, other: Money, total: Money, charged_by_supplier: Money, paid_elsewhere: Money}
     */
    public static function extras(Purchase $purchase): array
    {
        $out = ['freight' => Money::zero(), 'customs_duty' => Money::zero(), 'other' => Money::zero(), 'total' => Money::zero(), 'charged_by_supplier' => Money::zero(), 'paid_elsewhere' => Money::zero()];
        foreach ($purchase->receipts as $receipt) {
            $landed = $receipt->freight->plus($receipt->customs_duty)->plus($receipt->other_cost);
            $out['freight'] = $out['freight']->plus($receipt->freight);
            $out['customs_duty'] = $out['customs_duty']->plus($receipt->customs_duty);
            $out['other'] = $out['other']->plus($receipt->other_cost);
            $out['total'] = $out['total']->plus($landed);
            if ($receipt->costs_paid_to_supplier) {
                $out['charged_by_supplier'] = $out['charged_by_supplier']->plus($landed);
            } else {
                $out['paid_elsewhere'] = $out['paid_elsewhere']->plus($landed);
            }
        }

        return $out;
    }

    /**
     * The extra costs the supplier charged (owed to it), by kind, summed from the receipts whose
     * costs were on the supplier's bill (load `receipts`) — what the supplier invoice lists.
     *
     * @return array{freight: Money, customs_duty: Money, other: Money, total: Money}
     */
    public static function supplierCharged(Purchase $purchase): array
    {
        $out = ['freight' => Money::zero(), 'customs_duty' => Money::zero(), 'other' => Money::zero(), 'total' => Money::zero()];
        foreach ($purchase->receipts as $receipt) {
            if (! $receipt->costs_paid_to_supplier) {
                continue;
            }
            $out['freight'] = $out['freight']->plus($receipt->freight);
            $out['customs_duty'] = $out['customs_duty']->plus($receipt->customs_duty);
            $out['other'] = $out['other']->plus($receipt->other_cost);
            $out['total'] = $out['total']->plus($receipt->freight)->plus($receipt->customs_duty)->plus($receipt->other_cost);
        }

        return $out;
    }

    /** Goods received so far, at the supplier's price (Σ receipts' goods_value; load `receipts`). */
    public static function receivedGoods(Purchase $purchase): Money
    {
        return $purchase->receipts->reduce(static fn (Money $sum, $r): Money => $sum->plus($r->goods_value), Money::zero());
    }

    /** Refreshes `total` and `due_amount` from the stored components. */
    public static function refresh(Purchase $purchase): void
    {
        $purchase->total = $purchase->subtotal
            ->minus($purchase->discount)
            ->plus($purchase->shipping_cost)
            ->plus($purchase->customs_duty)
            ->plus($purchase->other_cost)
            ->plus($purchase->tax);
        $purchase->due_amount = $purchase->received_value->minus($purchase->paid_amount);
    }
}
