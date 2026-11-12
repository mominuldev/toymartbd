<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Enums\StockState;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\PriceDisplay;
use App\Domain\Catalog\Services\ProductDescription;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The storefront product page (P4-STO-05). Every money value is a decimal string and
 * every derived figure — discount percent, saving, stock state — is computed here; the
 * storefront formats and never does arithmetic (rule 1).
 *
 * Only active variants are listed. `attributes` are the variant-defining axes those
 * variants use (in attribute order, values in value order); each variant names its
 * values in `attribute_value_ids`. `default_variant_id` is the preselected variant.
 * `related` are in-stock product cards (StorefrontProductCard), best first.
 *
 * Needs ProductDetail::RELATIONS loaded.
 *
 * @property Product $resource
 */
#[SchemaName('StorefrontProduct')]
final class StorefrontProductResource extends ApiResource
{
    /** Widths of the gallery `url` (608 desktop stage at 2×) and `thumb_url` (96 at 2×). */
    public const int STAGE_WIDTH = 1200;

    public const int THUMB_WIDTH = 160;

    /**
     * @param  array<int, int>  $available  variant id → available units
     * @param  Collection<int, Product>  $related
     */
    public function __construct(Product $product, private readonly array $available, private readonly Collection $related)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $variants = $product->activeVariants;
        $brand = $product->brand;

        $default = $variants->first(static fn (ProductVariant $v): bool => $v->is_default) ?? $variants->first();
        $inStock = $variants->contains(fn (ProductVariant $v): bool => ($this->available[$v->id] ?? 0) > 0);

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            /** @var string|null */
            'name_bn' => $product->name_bn,
            /** @var 'simple'|'variable' */
            'type' => $product->type->value,
            /** @var array{name: string, name_bn: string|null, slug: string}|null */
            'brand' => $brand === null ? null : ['name' => $brand->name, 'name_bn' => $brand->name_bn, 'slug' => $brand->slug],
            /**
             * The primary category's path, root first, ending with the primary category.
             * Empty when that category is archived.
             *
             * @var list<array{name: string, name_bn: string|null, slug: string}>
             */
            'breadcrumbs' => self::breadcrumbs($product),
            /** @var string|null */
            'short_description' => $product->short_description,
            /** @var string|null */
            'short_description_bn' => $product->short_description_bn,
            /**
             * Sanitised rich-text HTML: p, h2, h3, strong, em, ul, ol, li, blockquote, br,
             * a[href] and img[src, alt, width, height] from our own media only.
             *
             * @var string|null
             */
            'description' => ProductDescription::html($product->description),
            /**
             * Sanitised rich-text HTML: p, h2, h3, strong, em, ul, ol, li, blockquote, br,
             * a[href] and img[src, alt, width, height] from our own media only.
             *
             * @var string|null
             */
            'description_bn' => ProductDescription::html($product->description_bn),
            /**
             * Free-form key/value pairs from the product's specifications, in their stored
             * order; empty values are dropped.
             *
             * @var list<array{key: string, value: string}>
             */
            'specifications' => self::specifications($product->specifications),
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
            /** @var string|null */
            'rating_avg' => $product->rating_avg === null ? null : number_format((float) $product->rating_avg, 2, '.', ''),
            'rating_count' => $product->rating_count,
            /**
             * True when at least one variant has stock.
             *
             * @var bool
             */
            'in_stock' => $inStock,
            /** @var int|null */
            'default_variant_id' => $default?->id,
            /** @var list<array{id: int, url: string, thumb_url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}> */
            'images' => self::images($product->images),
            /** @var list<array{id: int, name: string, name_bn: string|null, slug: string, type: 'select'|'color'|'text', values: list<array{id: int, value: string, value_bn: string|null, slug: string, color_hex: string|null}>}> */
            'attributes' => self::axes($variants),
            /** @var list<array{id: int, sku: string, name: string|null, name_bn: string|null, attribute_value_ids: list<int>, price: string, compare_at_price: string|null, discount_percent: int|null, saving: string|null, weight_grams: int, is_default: bool, status: 'active', available: int, stock_state: 'in_stock'|'low_stock'|'out_of_stock'}> */
            'variants' => $variants->map(fn (ProductVariant $v): array => $this->variant($v))->values()->all(),
            /** @var list<StorefrontProductCardResource> */
            'related' => StorefrontProductCardResource::collection($this->related),
        ];
    }

    /**
     * @return array{id: int, sku: string, name: string|null, name_bn: string|null, attribute_value_ids: list<int>, price: string, compare_at_price: string|null, discount_percent: int|null, saving: string|null, weight_grams: int, is_default: bool, status: 'active', available: int, stock_state: string}
     */
    private function variant(ProductVariant $variant): array
    {
        $compareAt = PriceDisplay::compareAt($variant->price, $variant->compare_at_price);
        $available = $this->available[$variant->id] ?? 0;

        /** @var list<int> $valueIds */
        $valueIds = $variant->attributeValues
            ->sortBy(static fn (ProductAttributeValue $v): array => [$v->attribute->position, $v->attribute_id])
            ->map(static fn (ProductAttributeValue $v): int => $v->id)
            ->values()
            ->all();

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name,
            'name_bn' => $variant->name_bn,
            'attribute_value_ids' => $valueIds,
            'price' => $variant->price->toDecimal(),
            'compare_at_price' => $compareAt?->toDecimal(),
            'discount_percent' => $compareAt === null ? null : PriceDisplay::discountPercent($variant->price, $compareAt),
            'saving' => $compareAt === null ? null : PriceDisplay::saving($variant->price, $compareAt)->toDecimal(),
            'weight_grams' => $variant->weight_grams,
            'is_default' => $variant->is_default,
            'status' => 'active',
            'available' => $available,
            'stock_state' => StockState::fromAvailable($available)->value,
        ];
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array{id: int, name: string, name_bn: string|null, slug: string, type: string, values: list<array{id: int, value: string, value_bn: string|null, slug: string, color_hex: string|null}>}>
     */
    private static function axes(Collection $variants): array
    {
        /** @var array<int, ProductAttribute> $attributes */
        $attributes = [];
        /** @var array<int, array<int, ProductAttributeValue>> $values */
        $values = [];

        foreach ($variants as $variant) {
            foreach ($variant->attributeValues as $value) {
                $attributes[$value->attribute_id] = $value->attribute;
                $values[$value->attribute_id][$value->id] = $value;
            }
        }

        uasort($attributes, static fn (ProductAttribute $a, ProductAttribute $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

        $axes = [];
        foreach ($attributes as $id => $attribute) {
            $list = array_values($values[$id]);
            usort($list, static fn (ProductAttributeValue $a, ProductAttributeValue $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);

            $axes[] = [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'name_bn' => $attribute->name_bn,
                'slug' => $attribute->slug,
                'type' => $attribute->type->value,
                'values' => array_map(static fn (ProductAttributeValue $v): array => [
                    'id' => $v->id,
                    'value' => $v->value,
                    'value_bn' => $v->value_bn,
                    'slug' => $v->slug,
                    'color_hex' => $v->color_hex,
                ], $list),
            ];
        }

        return $axes;
    }

    /** @return list<array{name: string, name_bn: string|null, slug: string}> */
    private static function breadcrumbs(Product $product): array
    {
        /** @var Category|null $category */
        $category = $product->primaryCategory;
        if ($category === null || ! $category->isActive()) {
            return [];
        }

        /** @var iterable<Category> $ancestors */
        $ancestors = $category->relationLoaded('ancestors') ? $category->ancestors : [];

        $crumbs = [];
        foreach ([...$ancestors, $category] as $crumb) {
            $crumbs[] = ['name' => $crumb->name, 'name_bn' => $crumb->name_bn, 'slug' => $crumb->slug];
        }

        return $crumbs;
    }

    /**
     * @param  array<string, mixed>|null  $specifications
     * @return list<array{key: string, value: string}>
     */
    private static function specifications(?array $specifications): array
    {
        $rows = [];
        foreach ($specifications ?? [] as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $rows[] = ['key' => (string) $key, 'value' => trim((string) $value)];
            }
        }

        return $rows;
    }

    /**
     * Ready images only, primary first, then gallery order.
     *
     * @param  Collection<int, ProductImage>  $images
     * @return list<array{id: int, url: string, thumb_url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}>
     */
    private static function images(Collection $images): array
    {
        $out = [];
        // The primary image leads (it is the card photo and the PDP's LCP), then gallery order.
        foreach ($images->sortBy(static fn (ProductImage $i): int => $i->is_primary ? 0 : 1) as $image) {
            $url = $image->url(self::STAGE_WIDTH);
            $thumb = $image->url(self::THUMB_WIDTH);
            if ($url === null || $thumb === null) {
                continue;
            }

            $out[] = [
                'id' => $image->id,
                'url' => $url,
                'thumb_url' => $thumb,
                'srcset_webp' => $image->srcset('webp'),
                'srcset_avif' => $image->srcset('avif'),
                'alt' => $image->alt('en'),
                'alt_bn' => $image->alt('bn'),
                'width' => $image->width,
                'height' => $image->height,
            ];
        }

        return $out;
    }
}
