<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Queries\ProductStockByLocation;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
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
        'brand', 'categories', 'variants.attributeValues', 'images',
        'productRelations.relatedProduct.images', 'productRelations.relatedProduct.activeVariants',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        // Variants read the product's VAT rate for their margin without a query each.
        $product->variants->each(static fn (ProductVariant $v) => $v->setRelation('product', $product));
        $canCost = ProductVariantResource::canViewCost($request);

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
             * Live on the storefront now (active and `published_at` reached): its slug
             * changes only with `acknowledge_slug_redirect`.
             */
            'is_live' => $product->isLive(),
            /**
             * Where the product is sold (stored flags).
             *
             * @var array{online_store: bool, pos: bool, facebook_instagram: bool, google_merchant: bool}
             */
            'sales_channels' => [
                'online_store' => $product->channel_online_store,
                'pos' => $product->channel_pos,
                'facebook_instagram' => $product->channel_social,
                'google_merchant' => $product->channel_google_merchant,
            ],
            /**
             * VAT included in the selling price, percent, decimal string ("0.00").
             *
             * @var string
             */
            'vat_rate' => (string) $product->vat_rate,
            /**
             * BSTI certification. `certificate` is the uploaded PDF (download it from
             * `GET /products/{product}/compliance/certificate`), null when none.
             * `expired`: the expiry date is before today (Dhaka).
             *
             * @var array{bsti_certificate_no: string|null, bsti_expires_on: string|null, expired: bool, certificate: array{name: string, size_bytes: int}|null}
             */
            'compliance' => ProductComplianceResource::block($product),
            /**
             * Margin of the default variant (a simple product's price), computed by the
             * server; only with catalog.view_cost. Null when its price or cost is not set.
             *
             * @var array{percent: string, profit: string, net_price: string}|null
             */
            'margin' => $this->when($canCost, fn (): ?array => self::defaultMargin($product)),
            /**
             * The lowest variant margin (archived variants included) and its variant;
             * only with catalog.view_cost. Null when no variant has a margin.
             *
             * @var array{percent: string, variant_id: int}|null
             */
            'margin_lowest' => $this->when($canCost, fn (): ?array => self::lowestMargin($product)),
            /**
             * Stock of all the product's variants per location (on hand, reserved,
             * available); only with inventory.view. Read-only — change it with a stock
             * adjustment.
             *
             * @var list<array{location_id: int, code: string, name: string, name_bn: string|null, is_default: bool, on_hand: int, reserved: int, available: int}>
             */
            'stock_by_location' => $this->when(ProductVariantResource::canViewStock($request), fn (): array => app(ProductStockByLocation::class)->forProduct($product->id)),
            /**
             * Retired slugs that now 301 to this product (newest first).
             *
             * @var list<array{old_slug: string, created_at: string}>
             */
            'slug_history' => SlugHistory::query()
                ->where('sluggable_type', SeoMeta::TYPE_PRODUCT)->where('sluggable_id', $product->id)
                ->orderByDesc('id')->limit(10)->get()
                ->map(static fn (SlugHistory $h): array => ['old_slug' => $h->old_slug, 'created_at' => self::utcIso($h->created_at)])
                ->values()->all(),
            /** Search & social (seo_meta), with the server's defaults, measurement and checklist. */
            'seo' => new ProductSeoResource($product, SeoMeta::forProduct($product->id)->first()),
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

    /** @return array{percent: string, profit: string, net_price: string}|null */
    private static function defaultMargin(Product $product): ?array
    {
        $default = $product->variants->first(static fn (ProductVariant $v): bool => $v->is_default) ?? $product->variants->first();

        return $default === null ? null : ProductVariantResource::marginFor($default);
    }

    /** @return array{percent: string, variant_id: int}|null */
    private static function lowestMargin(Product $product): ?array
    {
        $lowest = null;

        foreach ($product->variants as $variant) {
            /** @var ProductVariant $variant */
            $margin = ProductVariantResource::marginFor($variant);
            if ($margin !== null && ($lowest === null || bccomp($margin['percent'], $lowest['percent'], 1) < 0)) {
                $lowest = ['percent' => $margin['percent'], 'variant_id' => $variant->id];
            }
        }

        return $lowest;
    }

    /** `2026-11-11T09:30:00Z`. */
    public static function utcIso(?DateTimeInterface $at): string
    {
        return $at === null ? '' : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
