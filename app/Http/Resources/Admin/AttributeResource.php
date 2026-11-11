<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product attribute with its values in display order.
 *
 * @property ProductAttribute $resource
 */
#[SchemaName('Attribute')]
final class AttributeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attribute = $this->resource;

        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            /** @var string|null */
            'name_bn' => $attribute->name_bn,
            'slug' => $attribute->slug,
            /** @var 'select'|'color'|'text' */
            'type' => $attribute->type->value,
            'is_variant_defining' => $attribute->is_variant_defining,
            'is_filterable' => $attribute->is_filterable,
            'position' => $attribute->position,
            /** @var list<AttributeValueResource> */
            'values' => AttributeValueResource::collection($attribute->values),
        ];
    }
}
