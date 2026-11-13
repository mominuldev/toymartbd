<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\InventoryAlert;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An inventory alert: a low/out-of-stock level, or a drift the nightly reconciliation
 * found. For drift, `expected` is the ledger (or open-order) figure and `actual` what
 * stock_levels holds; for a stock alert `actual` is the available quantity and
 * `expected` the reorder point.
 *
 * @property InventoryAlert $resource
 */
#[SchemaName('InventoryAlert')]
final class InventoryAlertResource extends ApiResource
{
    public const array RELATIONS = ['variant.product.images', 'location', 'acknowledger'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;
        $alert->loadMissing(self::RELATIONS);
        $variant = $alert->variant;

        return [
            'id' => $alert->id,
            /** @var 'low_stock'|'out_of_stock'|'ledger_drift'|'reserved_drift' */
            'kind' => $alert->kind->value,
            /** @var 'open'|'resolved'|'acknowledged' */
            'status' => $alert->status->value,
            'variant_id' => $alert->variant_id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'product_name' => $variant->product->name,
            /** @var string|null */
            'product_name_bn' => $variant->product->name_bn,
            /** @var string|null */
            'variant_name' => $variant->name,
            /** @var string|null */
            'image_url' => InventoryVariantPart::imageUrl($variant),
            'location_id' => $alert->location_id,
            'location_code' => $alert->location->code,
            /** @var int|null */
            'expected' => $alert->expected,
            /** @var int|null */
            'actual' => $alert->actual,
            /**
             * actual − expected, for a drift alert.
             *
             * @var int|null
             */
            'difference' => $alert->kind->isDrift() ? $alert->difference() : null,
            /**
             * The Dhaka calendar day it was raised, YYYY-MM-DD.
             *
             * @var string
             *
             * @format date
             */
            'alert_date' => $alert->alert_date->toDateString(),
            /**
             * @var string
             *
             * @format date-time
             */
            'first_seen_at' => ProductResource::utcIso($alert->first_seen_at),
            /**
             * @var string
             *
             * @format date-time
             */
            'last_seen_at' => ProductResource::utcIso($alert->last_seen_at),
            /** @var string|null */
            'acknowledged_by' => $alert->acknowledger?->name,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'acknowledged_at' => $alert->acknowledged_at === null ? null : ProductResource::utcIso($alert->acknowledged_at),
            /** @var string|null */
            'note' => $alert->note,
        ];
    }
}
