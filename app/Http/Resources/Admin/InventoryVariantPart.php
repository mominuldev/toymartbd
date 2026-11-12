<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Shared pieces of the inventory resources: the variant thumbnail, and the
 * catalog.view_cost check that gates every cost and valuation figure (a role with
 * inventory.view but not catalog.view_cost — order operator, viewer — sees units only).
 */
final class InventoryVariantPart
{
    /** Eager loads for a variant's label and thumbnail. */
    public const array RELATIONS = ['product.images'];

    public static function canViewCost(Request $request): bool
    {
        return $request->user()?->can('viewCost', Product::class) ?? false;
    }

    public static function imageUrl(ProductVariant $variant): ?string
    {
        $images = $variant->product->images;
        $image = $images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $images->first();

        return $image?->url(160);
    }
}
