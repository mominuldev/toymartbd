<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Inventory valuation at cost (InventoryValuationReport): one row per variant × location
 * with stock, plus totals by location and by primary category. `as_at` null = now.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('InventoryValuation')]
final class InventoryValuationResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string|null The Dhaka date valued (end of that day); null = the live projection. */
            'as_at' => $r['as_at'],
            /** @var string */
            'sort' => $r['sort'],
            /**
             * `unit_cost` is 4 dp; `value` = on_hand × unit_cost, 2 dp. `estimated` = as-at
             * row whose movements carried no cost, valued at today's cost basis.
             *
             * @var list<array{variant_id: int, product_id: int, sku: string, name: string, name_bn: string|null, variant_name: string|null, category_id: int|null, category_name: string|null, category_name_bn: string|null, location_id: int, location_code: string, location_name: string, location_name_bn: string|null, on_hand: int, unit_cost: string, value: string, estimated: bool}>
             */
            'data' => $r['rows'],
            /** @var array{on_hand: int, value: string, rows: int, estimated_rows: int} */
            'totals' => $r['totals'],
            /** @var list<array{location_id: int, code: string, name: string, name_bn: string|null, on_hand: int, value: string, variants: int}> */
            'by_location' => $r['by_location'],
            /** @var list<array{category_id: int|null, name: string|null, name_bn: string|null, on_hand: int, value: string, variants: int}> */
            'by_category' => $r['by_category'],
            /** @var bool */
            'truncated' => $r['truncated'],
        ];
    }
}
