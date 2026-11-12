<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Inventory nav badge (Figma SidebarItem CountBadge 185:43): how many variants are
 * low on stock at the default location.
 *
 * @property array{location_id: int, count: int} $resource
 */
#[SchemaName('InventoryLowStockCount')]
final class InventoryLowStockCountResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The location counted — the default one.
             *
             * @var int
             */
            'location_id' => $this->resource['location_id'],
            /**
             * Variants with 0 < available ≤ reorder point there (up to a minute old).
             *
             * @var int
             */
            'count' => $this->resource['count'],
        ];
    }
}
