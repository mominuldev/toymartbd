<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An outgoing recommendation pair. `is_auto` rows come from a relation rule and are
 * read-only here; the PUT document carries only hand-curated rows.
 *
 * @property ProductRelation $resource
 */
#[SchemaName('ProductRelation')]
final class ProductRelationResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $relation = $this->resource;
        $related = $relation->relatedProduct;
        $image = $related->relationLoaded('images')
            ? ($related->images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $related->images->first())
            : null;
        $variant = $related->relationLoaded('activeVariants')
            ? ($related->activeVariants->first(static fn (ProductVariant $v): bool => $v->is_default) ?? $related->activeVariants->first())
            : null;

        return [
            'id' => $relation->id,
            'related_product_id' => $relation->related_product_id,
            /**
             * `image_url`: the primary image's 160 px rendition (null when none is ready);
             * `price`: the default variant's selling price, decimal string.
             *
             * @var array{id: int, name: string, name_bn: string|null, slug: string, status: 'draft'|'active'|'archived', image_url: string|null, price: string|null}
             */
            'related_product' => [
                'id' => $related->id,
                'name' => $related->name,
                'name_bn' => $related->name_bn,
                'slug' => $related->slug,
                'status' => $related->status->value,
                'image_url' => $image?->url(160),
                'price' => $variant?->price->toDecimal(),
            ],
            /** @var 'related'|'upsell'|'cross_sell'|'bundle'|'age_progression' */
            'type' => $relation->type->value,
            'position' => $relation->position,
            /** @var string|null */
            'reason_note' => $relation->reason_note,
            'is_auto' => $relation->is_auto,
        ];
    }
}
