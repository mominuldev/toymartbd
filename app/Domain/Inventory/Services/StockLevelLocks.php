<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * Row locks on stock_levels, always in ascending variant id order (rule 9). A missing
 * level row is created first with INSERT … IGNORE, so two first writers for the same
 * variant cannot both insert, and then locked like any other. Rows that already exist
 * are never touched by the INSERT (its duplicate-key S lock would deadlock with a
 * concurrent FOR UPDATE on the same row).
 *
 * A write that spans locations (TransferStock) locks through lockAcross(), which orders
 * by (variant_id, location_id): the global order every lock in the system follows, since
 * a single-location writer's ascending-variant pass is a subsequence of it.
 *
 * @internal for StockLedger, StockReservations, AdjustStock / ApproveStockAdjustment (which lock first to read on_hand and reserved before deciding), TransferStock and SetReorderLevels
 */
final class StockLevelLocks
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, StockLevel> variant id → locked level, ascending
     */
    public function lock(array $variantIds, int $locationId): array
    {
        $ids = array_values(array_unique($variantIds));
        sort($ids);

        // Only insert the rows that are missing. An INSERT IGNORE that hits an existing row
        // takes a shared lock on it; two transactions holding that S lock and both asking
        // for the X lock below deadlock (found by the 10-parallel-checkouts test on MySQL).
        $existing = array_map(intval(...), DB::table('stock_levels')
            ->where('location_id', $locationId)
            ->whereIn('variant_id', $ids)
            ->pluck('variant_id')
            ->all());
        $missing = array_values(array_diff($ids, $existing));

        if ($missing !== []) {
            $now = now();
            DB::table('stock_levels')->insertOrIgnore(array_map(static fn (int $id): array => [
                'variant_id' => $id,
                'location_id' => $locationId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        $levels = StockLevel::query()
            ->where('location_id', $locationId)
            ->whereIn('variant_id', $ids)
            ->orderBy('variant_id')
            ->lockForUpdate()
            ->get();

        $byVariant = [];
        foreach ($levels as $level) {
            $byVariant[$level->variant_id] = $level;
        }

        return $byVariant;
    }

    /**
     * Lock the levels of several variants at several locations in ONE pass ordered by
     * (variant_id, location_id) — a transfer must never lock all of one location and
     * then all of the other, or a transfer the opposite way would deadlock against it.
     *
     * @param  list<int>  $variantIds
     * @param  list<int>  $locationIds
     * @return array<int, array<int, StockLevel>> location id → variant id → locked level
     */
    public function lockAcross(array $variantIds, array $locationIds): array
    {
        $ids = array_values(array_unique($variantIds));
        sort($ids);
        $locations = array_values(array_unique($locationIds));
        sort($locations);

        $existing = [];
        DB::table('stock_levels')->whereIn('location_id', $locations)->whereIn('variant_id', $ids)
            ->get(['variant_id', 'location_id'])
            ->each(static function (object $row) use (&$existing): void {
                $existing[(int) $row->location_id.':'.(int) $row->variant_id] = true;
            });
        $now = now();
        $missing = [];
        foreach ($ids as $variantId) {
            foreach ($locations as $locationId) {
                if (! isset($existing[$locationId.':'.$variantId])) {
                    $missing[] = ['variant_id' => $variantId, 'location_id' => $locationId, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        if ($missing !== []) {
            DB::table('stock_levels')->insertOrIgnore($missing);
        }

        $levels = StockLevel::query()
            ->whereIn('location_id', $locations)
            ->whereIn('variant_id', $ids)
            ->orderBy('variant_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();

        $byLocation = array_fill_keys($locations, []);
        foreach ($levels as $level) {
            $byLocation[$level->location_id][$level->variant_id] = $level;
        }

        return $byLocation;
    }

    /**
     * Fire StockLevelChanged for each variant — held until the transaction commits.
     *
     * @param  list<int>  $variantIds
     */
    public function announce(array $variantIds): void
    {
        $products = DB::table('product_variants')->whereIn('id', $variantIds)->pluck('product_id', 'id');

        foreach ($variantIds as $variantId) {
            StockLevelChanged::dispatch($variantId, (int) $products[$variantId]);
        }
    }
}
