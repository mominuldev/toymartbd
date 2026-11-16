<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\DTOs\PurchaseQuote;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prices a PO before it is saved (`POST /purchases/quote`): each line total and the order
 * total exactly as CreatePurchase computes them, plus — when a supplier is named — the unit
 * cost of the last time this supplier sold us each variant (null = new to this supplier).
 * Reads only. A line discount above its value is the same 422 CreatePurchase raises.
 */
final class PurchaseQuoter
{
    /** @param  list<PurchaseLineData>  $lines */
    public function quote(array $lines, ?int $supplierId = null): PurchaseQuote
    {
        $subtotal = Money::zero();
        $discount = Money::zero();
        $errors = [];
        foreach ($lines as $index => $line) {
            $gross = PurchaseTotals::lineGross($line);
            if ($line->discount->greaterThan($gross)) {
                $errors["lines.{$index}.discount"] = __('purchasing.lines.discount_exceeds');
            }
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($line->discount);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $last = $supplierId === null ? [] : $this->lastUnitCosts($supplierId, array_map(static fn (PurchaseLineData $l): int => $l->variantId, $lines));

        return new PurchaseQuote(
            lines: array_map(static fn (PurchaseLineData $line): array => [
                'variant_id' => $line->variantId,
                'quantity' => $line->quantity,
                'line_total' => PurchaseTotals::lineTotal($line),
                'last_unit_cost' => $last[$line->variantId] ?? null,
            ], $lines),
            subtotal: $subtotal,
            discount: $discount,
            total: $subtotal->minus($discount),
            supplierKnown: $supplierId !== null,
        );
    }

    /**
     * variant id → the unit cost (4 dp) on this supplier's most recent placed PO for it.
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>
     */
    private function lastUnitCosts(int $supplierId, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.supplier_id', $supplierId)
            ->whereNotIn('purchases.status', [PurchaseStatus::Draft->value, PurchaseStatus::Cancelled->value])
            ->whereIn('purchase_items.variant_id', $variantIds)
            ->orderByDesc('purchases.order_date')
            ->orderByDesc('purchase_items.id')
            ->get(['purchase_items.variant_id', 'purchase_items.unit_cost']);

        $out = [];
        foreach ($rows as $row) {
            $variant = (int) $row->variant_id;
            if (! isset($out[$variant]) && is_numeric($row->unit_cost)) {
                $out[$variant] = bcadd((string) $row->unit_cost, '0', 4);
            }
        }

        return $out;
    }
}
