<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The managers' daily stock digest (PRD I-09; SYSTEM-ARCHITECTURE §6: 09:00 Asia/Dhaka).
 * For one Dhaka calendar date D (rule 11) it reports the alerts RAISED on D — bucketed by
 * `alert_date`, the Dhaka day stamped when each opened, never the UTC date — beside the
 * full picture still open now: low and out-of-stock variants, unacknowledged drift, and
 * adjustments waiting for approval. Read-only.
 */
final class InventoryDigest
{
    /**
     * @param  string  $date  Y-m-d, a Dhaka date
     * @return array{
     *     date: string,
     *     raised: array{low_stock: int, out_of_stock: int, drift: int},
     *     open: array{low_stock: int, out_of_stock: int, drift: int},
     *     pending_adjustments: int,
     *     rows: list<array{kind: string, sku: string, product_name: string, variant_name: string|null, location_code: string, available: int, reorder_point: int, alert_date: string, raised_on_date: bool}>,
     *     drift_rows: list<array{kind: string, sku: string, product_name: string, location_code: string, expected: int, actual: int, alert_date: string}>,
     *     empty: bool
     * }
     */
    public function compile(string $date): array
    {
        $limit = (int) config('inventory.alerts.digest_rows', 25);
        $kinds = static fn (array $kinds): array => array_map(static fn (InventoryAlertKind $k): string => $k->value, $kinds);
        $count = static fn (Builder $q, InventoryAlertKind ...$kind): int => (clone $q)->whereIn('kind', $kinds($kind))->count();

        /** @var Builder<InventoryAlert> $raised */
        $raised = InventoryAlert::query()->whereDate('alert_date', $date);
        /** @var Builder<InventoryAlert> $open */
        $open = InventoryAlert::query()->open();

        $rows = InventoryAlert::query()->open()
            ->whereIn('kind', $kinds(InventoryAlertKind::stockKinds()))
            ->with(['variant.product', 'location'])
            ->orderByRaw('CASE WHEN kind = ? THEN 0 ELSE 1 END', [InventoryAlertKind::OutOfStock->value])
            ->orderByDesc('alert_date')->orderBy('id')
            ->limit($limit)->get()
            ->map(static fn (InventoryAlert $a): array => [
                'kind' => $a->kind->value,
                'sku' => (string) $a->variant->sku,
                'product_name' => (string) $a->variant->product->name,
                'variant_name' => $a->variant->name,
                'location_code' => $a->location->code,
                'available' => (int) $a->actual,
                'reorder_point' => (int) $a->expected,
                'alert_date' => $a->alert_date->toDateString(),
                'raised_on_date' => $a->alert_date->toDateString() === $date,
            ])->values()->all();

        $driftRows = InventoryAlert::query()->open()
            ->whereIn('kind', $kinds(InventoryAlertKind::driftKinds()))
            ->with(['variant.product', 'location'])
            ->orderBy('id')->limit($limit)->get()
            ->map(static fn (InventoryAlert $a): array => [
                'kind' => $a->kind->value,
                'sku' => (string) $a->variant->sku,
                'product_name' => (string) $a->variant->product->name,
                'location_code' => $a->location->code,
                'expected' => (int) $a->expected,
                'actual' => (int) $a->actual,
                'alert_date' => $a->alert_date->toDateString(),
            ])->values()->all();

        $digest = [
            'date' => $date,
            'raised' => [
                'low_stock' => $count($raised, InventoryAlertKind::LowStock),
                'out_of_stock' => $count($raised, InventoryAlertKind::OutOfStock),
                'drift' => $count($raised, ...InventoryAlertKind::driftKinds()),
            ],
            'open' => [
                'low_stock' => $count($open, InventoryAlertKind::LowStock),
                'out_of_stock' => $count($open, InventoryAlertKind::OutOfStock),
                'drift' => $count($open, ...InventoryAlertKind::driftKinds()),
            ],
            'pending_adjustments' => StockAdjustment::query()->where('status', StockAdjustmentStatus::Pending->value)->count(),
            'rows' => $rows,
            'drift_rows' => $driftRows,
        ];
        $digest['empty'] = array_sum($digest['raised']) + array_sum($digest['open']) + $digest['pending_adjustments'] === 0;

        return $digest;
    }
}
