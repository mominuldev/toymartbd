<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One attribute value: `{ id, attribute_id, value, value_bn, slug, color_hex, position }`.
 * `color_hex` is `#RRGGBB` for colour-type attributes, otherwise null.
 *
 * @property ProductAttributeValue $resource
 */
#[SchemaName('AttributeValue')]
final class AttributeValueResource extends ApiResource
{
    /**
     * @return array{id: int, attribute_id: int, value: string, value_bn: string|null, slug: string, color_hex: string|null, position: int}
     */
    public function toArray(Request $request): array
    {
        $value = $this->resource;

        return [
            'id' => $value->id,
            'attribute_id' => $value->attribute_id,
            'value' => $value->value,
            'value_bn' => $value->value_bn,
            'slug' => $value->slug,
            'color_hex' => $value->color_hex,
            'position' => $value->position,
        ];
    }
}
