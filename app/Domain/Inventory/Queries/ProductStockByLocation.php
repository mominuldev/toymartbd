<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Read-only: one product's stock per location, summed over all its variants (the
 * product form's "Stock by branch" card, Figma 241:37976). Every active location is
 * listed, zeros included, plus any inactive one still holding stock. Display only —
 * never a stock decision (the write path locks its own rows).
 */
final class ProductStockByLocation
{
    /**
     * @return list<array{location_id: int, code: string, name: string, name_bn: string|null, is_default: bool, on_hand: int, reserved: int, available: int}>
     */
    public function forProduct(int $productId): array
    {
        $totals = DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
            ->where('product_variants.product_id', $productId)
            ->groupBy('stock_levels.location_id')
            ->select('stock_levels.location_id', DB::raw('SUM(stock_levels.on_hand) as on_hand'), DB::raw('SUM(stock_levels.reserved) as reserved'))
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->location_id);

        $locations = DB::table('stock_locations')
            ->where(static function ($q) use ($totals): void {
                $q->where('is_active', true)->orWhereIn('id', $totals->keys()->all());
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'name_bn', 'is_default']);

        $rows = [];
        foreach ($locations as $location) {
            $total = $totals->get((int) $location->id);
            $onHand = (int) ($total->on_hand ?? 0);
            $reserved = (int) ($total->reserved ?? 0);
            $rows[] = [
                'location_id' => (int) $location->id,
                'code' => (string) $location->code,
                'name' => (string) $location->name,
                'name_bn' => $location->name_bn === null ? null : (string) $location->name_bn,
                'is_default' => (bool) $location->is_default,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => max(0, $onHand - $reserved),
            ];
        }

        return $rows;
    }
}
