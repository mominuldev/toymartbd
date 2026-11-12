<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\Enums\VariantStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only availability for the storefront, search and cart: `available =
 * on_hand - reserved` (DATABASE-DESIGN §3.2), summed across stock locations (MVP has
 * one). A variant with no stock_levels row has 0 available. Never use this for a
 * stock *decision* inside a write — the write path locks the rows itself
 * ({@see StockLedger}, {@see StockReservations}).
 */
final class StockAvailability implements AvailableStockQuery
{
    /**
     * @param  array<int|string, int>  $variantIds
     * @return array<int, int> variant id → available units (never negative); every requested id is present
     */
    public function forVariants(array $variantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        if ($ids === []) {
            return [];
        }

        $result = array_fill_keys($ids, 0);

        $rows = DB::table('stock_levels')
            ->whereIn('variant_id', $ids)
            ->groupBy('variant_id')
            ->select('variant_id', DB::raw('SUM(on_hand - reserved) as available'))
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->variant_id] = max(0, (int) $row->available);
        }

        return $result;
    }

    /**
     * Products (from the given ids) with at least one active variant whose available > 0.
     *
     * @param  array<int|string, int>  $productIds
     * @return list<int>
     */
    public function inStockProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        return DB::table('product_variants')
            ->join('stock_levels', 'stock_levels.variant_id', '=', 'product_variants.id')
            ->whereIn('product_variants.product_id', $ids)
            ->where('product_variants.status', VariantStatus::Active->value)
            ->groupBy('product_variants.id', 'product_variants.product_id')
            ->havingRaw('SUM(stock_levels.on_hand - stock_levels.reserved) > 0')
            ->pluck('product_variants.product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * On-hand, reserved and available per variant of one product (admin display).
     * Variants without a level row read as zeros.
     *
     * @return array<int, array{on_hand: int, reserved: int, available: int}>
     */
    public function levelsForProduct(int $productId): array
    {
        $rows = DB::table('product_variants')
            ->leftJoin('stock_levels', 'stock_levels.variant_id', '=', 'product_variants.id')
            ->where('product_variants.product_id', $productId)
            ->groupBy('product_variants.id')
            ->select(
                'product_variants.id',
                DB::raw('COALESCE(SUM(stock_levels.on_hand), 0) as on_hand'),
                DB::raw('COALESCE(SUM(stock_levels.reserved), 0) as reserved'),
            )
            ->get();

        $levels = [];
        foreach ($rows as $row) {
            $onHand = (int) $row->on_hand;
            $reserved = (int) $row->reserved;
            $levels[(int) $row->id] = ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => max(0, $onHand - $reserved)];
        }

        return $levels;
    }

    public function availableByVariant(): Builder
    {
        return DB::table('stock_levels')
            ->select('variant_id')
            ->selectRaw('SUM(on_hand - reserved) as available')
            ->groupBy('variant_id');
    }
}
