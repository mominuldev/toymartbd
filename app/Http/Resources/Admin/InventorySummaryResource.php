<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The inventory overview's stat cards, location cards and tab counts for one location
 * (StockOverview::summary). Values at cost are present only with catalog.view_cost.
 *
 * @property array{
 *     location_id: int,
 *     counts: array{all: int, low: int, out: int, overstock: int, idle: int},
 *     on_hand: int,
 *     reserved: int,
 *     value: string,
 *     total_value: string,
 *     locations: list<array{id: int, code: string, name: string, name_bn: string|null, description: string|null, description_bn: string|null, is_default: bool, on_hand: int, value: string}>
 * } $resource
 */
#[SchemaName('InventorySummary')]
final class InventorySummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            /**
             * The location the counts and the on-hand figures are for.
             *
             * @var int
             */
            'location_id' => $summary['location_id'],
            /**
             * Rows per tab: every variant, low (0 < available ≤ reorder point), out (available ≤ 0), overstock (reorder quantity set and available > reorder point + 2 × reorder quantity), idle (stock but no movement for 90 days).
             *
             * @var array{all: int, low: int, out: int, overstock: int, idle: int}
             */
            'counts' => $summary['counts'],
            /** @var int */
            'on_hand' => $summary['on_hand'],
            /**
             * Units held for open orders, not yet shipped.
             *
             * @var int
             */
            'reserved' => $summary['reserved'],
            /**
             * Stock value at cost at this location; only with catalog.view_cost.
             *
             * @var string
             */
            'value' => $this->when($viewCost, $summary['value']),
            /**
             * Stock value at cost across every active location; only with catalog.view_cost.
             *
             * @var string
             */
            'total_value' => $this->when($viewCost, $summary['total_value']),
            /**
             * Every active location, the default first. `value` only with catalog.view_cost.
             *
             * @var list<array{id: int, code: string, name: string, name_bn: string|null, description: string|null, description_bn: string|null, is_default: bool, on_hand: int, value?: string}>
             */
            'locations' => array_map(static function (array $location) use ($viewCost): array {
                if (! $viewCost) {
                    unset($location['value']);
                }

                return $location;
            }, $summary['locations']),
        ];
    }
}
