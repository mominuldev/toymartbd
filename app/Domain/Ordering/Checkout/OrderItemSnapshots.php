<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Checkout;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockValuation;

/**
 * Builds `order_items` rows from priced lines (DATABASE-DESIGN §3.4): every name, SKU,
 * attribute and image as it reads now, the line's own price/discount/total from the
 * pricing pipeline, and `cost_price_snapshot` — the variant's weighted-average cost
 * (`stock_levels.avg_cost` once purchases exist, the variant's `cost_price` until then;
 * {@see StockValuation::costBasis()}). Written once; the model refuses to change it (rule 7).
 *
 * Call after the reservation, inside the order transaction: the stock rows are already
 * locked, so the cost read is the one the reservation saw.
 */
final class OrderItemSnapshots
{
    /**
     * @param  list<LineBreakdown>  $lines
     * @return list<array<string, mixed>> order_items attributes (no order_id)
     */
    public function build(array $lines, int $locationId): array
    {
        $ids = array_map(static fn (LineBreakdown $l): int => $l->variant->id, $lines);
        $variants = ProductVariant::query()
            ->with(['product.images', 'attributeValues.attribute'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
        $avgCosts = StockLevel::query()->where('location_id', $locationId)->whereIn('variant_id', $ids)->pluck('avg_cost', 'variant_id');

        $rows = [];
        foreach ($lines as $line) {
            /** @var ProductVariant $variant */
            $variant = $variants[$line->variant->id];
            $product = $variant->product;
            $avg = $avgCosts[$variant->id] ?? null;

            $rows[] = [
                'variant_id' => $variant->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_name_bn' => $product->name_bn,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'image_path' => self::image($variant)?->path,
                'variant_attributes' => self::attributes($variant),
                'unit_price' => $line->unitPrice,
                'quantity' => $line->quantity,
                'discount' => $line->discount(),
                'line_total' => $line->total,
                'cost_price_snapshot' => StockValuation::costBasis(is_scalar($avg) ? (string) $avg : null, $variant->cost_price->toDecimal()),
                'recommendation_source' => null,
            ];
        }

        return $rows;
    }

    /** The variant's own image, else the product's primary, else its first. */
    private static function image(ProductVariant $variant): ?ProductImage
    {
        $images = $variant->product->images->sortBy('position');

        return $images->firstWhere('variant_id', $variant->id)
            ?? $images->firstWhere('is_primary', true)
            ?? $images->first();
    }

    /** @return array<string, string>|null attribute name → value, e.g. {"Colour": "Red"} */
    private static function attributes(ProductVariant $variant): ?array
    {
        $values = [];
        foreach ($variant->attributeValues as $value) {
            $values[$value->attribute->name] = $value->value;
        }

        return $values === [] ? null : $values;
    }
}
