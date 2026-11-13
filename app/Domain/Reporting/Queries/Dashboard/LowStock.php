<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockOverview;

/**
 * Low stock at the default location, by the inventory overview's own rule
 * ({@see StockOverview}: 0 < available ≤ reorder point, available = on hand − reserved) so
 * the dashboard, the Inventory "Low stock" tab and the nav badge always show the same number.
 * Current state — not range-bound.
 */
final readonly class LowStock
{
    public function __construct(private StockOverview $overview) {}

    /**
     * Null when no stock location exists yet.
     *
     * @return array{location_id: int, low: int, out: int, rows: list<array{variant_id: int, product_id: int, name: string, name_bn: string|null, variant_name: string|null, sku: string, on_hand: int, reserved: int, available: int, reorder_point: int}>}|null
     */
    public function summary(int $limit = 5): ?array
    {
        $locationId = StockLocation::query()->where('is_default', true)->orderBy('id')->value('id');
        if ($locationId === null) {
            return null;
        }
        $locationId = (int) $locationId;

        $low = $this->overview->rows($locationId);
        $this->overview->whereState($low, StockOverview::LOW, $locationId);
        $out = $this->overview->rows($locationId);
        $this->overview->whereState($out, StockOverview::OUT, $locationId);

        $rows = (clone $low)
            ->addSelect(['products.name as product_name', 'products.name_bn as product_name_bn'])
            ->orderByRaw(StockOverview::AVAILABLE_SQL.' asc')
            ->orderBy('product_variants.id')
            ->limit($limit)
            ->get()
            ->map(static function ($variant): array {
                $onHand = (int) $variant->getAttribute('on_hand');
                $reserved = (int) $variant->getAttribute('reserved');

                return [
                    'variant_id' => (int) $variant->getKey(),
                    'product_id' => (int) $variant->getAttribute('product_id'),
                    'name' => (string) $variant->getAttribute('product_name'),
                    'name_bn' => $variant->getAttribute('product_name_bn') === null ? null : (string) $variant->getAttribute('product_name_bn'),
                    'variant_name' => $variant->getAttribute('name') === null ? null : (string) $variant->getAttribute('name'),
                    'sku' => (string) $variant->getAttribute('sku'),
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $onHand - $reserved,
                    'reorder_point' => (int) $variant->getAttribute('reorder_point'),
                ];
            })
            ->values()
            ->all();

        return ['location_id' => $locationId, 'low' => $low->count(), 'out' => $out->count(), 'rows' => $rows];
    }
}
