<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The ledger's reconciliation strip (StockLedgerReport::reconciliation): units in and
 * out over the period, and whether SUM(movements) = stock_levels.on_hand holds for every
 * variant in scope (DATABASE-DESIGN §7.1). Drift is reported, never repaired.
 *
 * @property array{opening: int, in: int, out: int, closing: int, variants_checked: int, drift_count: int, drift_variant_ids: list<int>, balanced: bool, stock_value: string} $resource
 */
#[SchemaName('InventoryReconciliation')]
final class InventoryReconciliationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /**
             * On hand before the period (0 without `from`).
             *
             * @var int
             */
            'opening' => $r['opening'],
            /**
             * Units in during the period (≥ 0).
             *
             * @var int
             */
            'in' => $r['in'],
            /**
             * Units out during the period (≤ 0).
             *
             * @var int
             */
            'out' => $r['out'],
            /**
             * opening + in + out.
             *
             * @var int
             */
            'closing' => $r['closing'],
            /**
             * Variants (with a stock level in scope) whose ledger was checked.
             *
             * @var int
             */
            'variants_checked' => $r['variants_checked'],
            /** @var int */
            'drift_count' => $r['drift_count'],
            /**
             * Up to 50 variant ids whose ledger sum differs from on_hand.
             *
             * @var list<int>
             */
            'drift_variant_ids' => $r['drift_variant_ids'],
            /**
             * True when the ledger matches the stock levels for every variant in scope.
             *
             * @var bool
             */
            'balanced' => $r['balanced'],
            /**
             * Current stock value at cost in scope; only with catalog.view_cost.
             *
             * @var string
             */
            'stock_value' => $this->when(InventoryVariantPart::canViewCost($request), $r['stock_value']),
        ];
    }
}
