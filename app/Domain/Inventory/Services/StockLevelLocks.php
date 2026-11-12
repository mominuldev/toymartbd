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
 * @internal for StockLedger, StockReservations, AdjustStock (which locks first to read on_hand and reserved before deciding) and SetReorderLevels
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
