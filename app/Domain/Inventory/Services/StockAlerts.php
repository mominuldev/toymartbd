<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * Low-stock and out-of-stock alerts against reorder points (PRD I-09). Re-evaluates one
 * variant's levels at every location after its stock moved (StockAlertsOnLevelChange):
 *
 *   out_of_stock  available ≤ 0, where a reorder point is set or at the default location
 *   low_stock     0 < available ≤ reorder point (the overview's Low rule)
 *
 * Only for a sellable variant (active, product not archived) — a retired line running
 * out is not news. One open alert per (variant, location, kind): a repeat refreshes it,
 * a recovery resolves it, and `alert_date` is the Dhaka day it opened (rule 11) — what
 * the daily digest buckets by. Read-only on stock; it never changes a level.
 */
final class StockAlerts
{
    public function evaluate(int $variantId): void
    {
        $sellable = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.id', $variantId)
            ->where('product_variants.status', VariantStatus::Active->value)
            ->where('products.status', '!=', ProductStatus::Archived->value)
            ->exists();
        $defaultId = StockLocation::defaultId();

        $levels = DB::table('stock_levels')->where('variant_id', $variantId)->get(['location_id', 'on_hand', 'reserved', 'reorder_point']);
        foreach ($levels as $level) {
            $locationId = (int) $level->location_id;
            $available = (int) $level->on_hand - (int) $level->reserved;
            $point = (int) $level->reorder_point;

            $kind = match (true) {
                ! $sellable => null,
                $available <= 0 && ($point > 0 || $locationId === $defaultId) => InventoryAlertKind::OutOfStock,
                $available > 0 && $available <= $point => InventoryAlertKind::LowStock,
                default => null,
            };

            $this->sync($variantId, $locationId, $kind, $available, $point);
        }
    }

    private function sync(int $variantId, int $locationId, ?InventoryAlertKind $kind, int $available, int $point): void
    {
        $now = now();
        $open = InventoryAlert::query()->open()
            ->where('variant_id', $variantId)->where('location_id', $locationId)
            ->whereIn('kind', array_map(static fn (InventoryAlertKind $k): string => $k->value, InventoryAlertKind::stockKinds()))
            ->get();

        $current = null;
        foreach ($open as $alert) {
            if ($alert->kind === $kind && $current === null) {
                $current = $alert;

                continue;
            }
            $alert->status = InventoryAlertStatus::Resolved;
            $alert->resolved_at = $now;
            $alert->save();
        }

        if ($kind === null) {
            return;
        }
        if ($current !== null) {
            $current->actual = $available;
            $current->expected = $point;
            $current->last_seen_at = $now;
            $current->save();

            return;
        }

        InventoryAlert::query()->create([
            'kind' => $kind,
            'status' => InventoryAlertStatus::Open,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'expected' => $point,
            'actual' => $available,
            'alert_date' => DhakaDate::today(),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
