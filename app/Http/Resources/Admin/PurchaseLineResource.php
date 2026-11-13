<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\PurchaseItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One PO line. Cost figures only with catalog.view_cost.
 *
 * @property PurchaseItem $resource
 */
#[SchemaName('PurchaseLine')]
final class PurchaseLineResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $variant = $item->variant;
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            'id' => $item->id,
            'variant_id' => $item->variant_id,
            'product_id' => $variant->product_id,
            'product_name' => $variant->product->name,
            /** @var string|null */
            'product_name_bn' => $variant->product->name_bn,
            /** @var string|null */
            'variant_name' => $variant->name,
            /** @var string|null */
            'variant_name_bn' => $variant->name_bn,
            'sku' => $variant->sku,
            /** @var string|null */
            'image_url' => InventoryVariantPart::imageUrl($variant),
            'quantity_ordered' => $item->quantity_ordered,
            'quantity_received' => $item->quantity_received,
            'quantity_outstanding' => $item->quantityOutstanding(),
            /**
             * Supplier price per unit, 4 dp.
             *
             * @var string
             */
            'unit_cost' => $this->when($viewCost, $item->unit_cost),
            /** @var string */
            'discount' => $this->when($viewCost, fn (): string => $item->discount->toDecimal()),
            /** @var string */
            'line_total' => $this->when($viewCost, fn (): string => $item->line_total->toDecimal()),
            /**
             * Quantity-weighted landed cost over every receipt so far, 4 dp; null before the first.
             *
             * @var string|null
             */
            'landed_unit_cost' => $this->when($viewCost, $item->landed_unit_cost),
        ];
    }
}
