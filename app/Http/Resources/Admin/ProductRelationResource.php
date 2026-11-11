<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductRelation;
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

        return [
            'id' => $relation->id,
            'related_product_id' => $relation->related_product_id,
            /**
             * @var array{id: int, name: string, name_bn: string|null, slug: string, status: 'draft'|'active'|'archived'}
             */
            'related_product' => [
                'id' => $related->id,
                'name' => $related->name,
                'name_bn' => $related->name_bn,
                'slug' => $related->slug,
                'status' => $related->status->value,
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
