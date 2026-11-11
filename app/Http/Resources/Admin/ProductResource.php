<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product in the admin, as the product form edits it: fields, categories, the
 * variant matrix (`options` + `variants`, archived variants included), images and
 * curated plus rule-generated relations. The PUT document mirrors this shape.
 *
 * @property Product $resource
 */
#[SchemaName('Product')]
final class ProductResource extends ApiResource
{
    public const array RELATIONS = [
        'brand', 'categories', 'variants.attributeValues', 'images', 'productRelations.relatedProduct',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            /** @var string|null */
            'name_bn' => $product->name_bn,
            'slug' => $product->slug,
            /** @var 'simple'|'variable' */
            'type' => $product->type->value,
            /** @var 'draft'|'active'|'archived' */
            'status' => $product->status->value,
            /** @var int|null */
            'brand_id' => $product->brand_id,
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'brand' => $product->brand === null ? null : [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
                'name_bn' => $product->brand->name_bn,
            ],
            'primary_category_id' => $product->primary_category_id,
            /** @var list<int> */
            'category_ids' => $product->categories->modelKeys(),
            /** @var list<array{id: int, name: string, name_bn: string|null, slug: string}> */
            'categories' => $product->categories->map(static fn (Category $c): array => [
                'id' => $c->id, 'name' => $c->name, 'name_bn' => $c->name_bn, 'slug' => $c->slug,
            ])->values()->all(),
            /** @var string|null */
            'short_description' => $product->short_description,
            /** @var string|null */
            'short_description_bn' => $product->short_description_bn,
            /** @var string|null */
            'description' => $product->description,
            /** @var string|null */
            'description_bn' => $product->description_bn,
            /** @var array<string, string|null>|null */
            'specifications' => $product->specifications,
            /** @var int|null */
            'age_min_months' => $product->age_min_months,
            /** @var int|null */
            'age_max_months' => $product->age_max_months,
            /** @var 'boys'|'girls'|'unisex'|null */
            'gender' => $product->gender?->value,
            'is_educational' => $product->is_educational,
            /** @var string|null */
            'safety_note' => $product->safety_note,
            /** @var string|null */
            'safety_note_bn' => $product->safety_note_bn,
            /** @var int|null */
            'warranty_months' => $product->warranty_months,
            'is_featured' => $product->is_featured,
            /**
             * ISO 8601, UTC.
             *
             * @var string|null
             *
             * @format date-time
             */
            'published_at' => $product->published_at === null ? null : self::utcIso($product->published_at),
            /**
             * The selected values of each variant-defining attribute, derived from the
             * active variants. Empty for a simple product.
             *
             * @var list<array{attribute_id: int, value_ids: list<int>}>
             */
            'options' => self::options($product),
            /** @var list<ProductVariantResource> */
            'variants' => ProductVariantResource::collection($product->variants),
            /** @var list<ProductImageResource> */
            'images' => ProductImageResource::collection($product->images),
            /** @var list<ProductRelationResource> */
            'relations' => ProductRelationResource::collection($product->productRelations),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => self::utcIso($product->created_at),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'updated_at' => self::utcIso($product->updated_at),
        ];
    }

    /** @return list<array{attribute_id: int, value_ids: list<int>}> */
    private static function options(Product $product): array
    {
        $byAttribute = [];

        foreach ($product->variants as $variant) {
            /** @var ProductVariant $variant */
            if (! $variant->isActive()) {
                continue;
            }

            foreach ($variant->attributeValues as $value) {
                /** @var ProductAttributeValue $value */
                $byAttribute[$value->attribute_id][$value->id] = true;
            }
        }

        $options = [];
        foreach ($byAttribute as $attributeId => $valueIds) {
            $options[] = ['attribute_id' => $attributeId, 'value_ids' => array_keys($valueIds)];
        }

        return $options;
    }

    /** `2026-11-11T09:30:00Z`. */
    public static function utcIso(?DateTimeInterface $at): string
    {
        return $at === null ? '' : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
