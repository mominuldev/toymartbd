<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductSlugs;
use App\Domain\Catalog\Services\VariantSkus;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate a product (P4-CAT-11) as a starting point for a similar one: fields,
 * categories, every variant (archived ones included, so the matrix keeps its
 * shape) with its attribute values and prices, image rows and curated relations.
 *
 * The copy is a DRAFT named "<name> (copy)" with a new slug and new generated SKUs;
 * barcodes are not copied (they identify a physical item and are UNIQUE). Ratings and
 * view counts start at zero. Image rows point at the same files until the media
 * pipeline (P4-CAT-07) gives each upload its own. SEO fields join the copy when the
 * Seo domain's tables exist (P7).
 */
final readonly class DuplicateProduct
{
    public function __construct(private ProductSlugs $slugs, private VariantSkus $skus) {}

    public function handle(Product $source, ?int $actorId = null): Product
    {
        return DB::transaction(function () use ($source, $actorId): Product {
            $source = Product::query()->with(['categories', 'variants.attributeValues.attribute', 'images', 'productRelations'])->findOrFail($source->id);

            // The BSTI certificate file belongs to the source's row (deleting it would orphan the copy); the number travels.
            $copy = $source->replicate(['slug', 'status', 'published_at', 'rating_avg', 'rating_count', 'view_count', 'deleted_at', 'bsti_certificate_path', 'bsti_certificate_name', 'bsti_certificate_size']);
            $copy->name = mb_substr($source->name.' (copy)', 0, 160);
            $copy->name_bn = $source->name_bn !== null ? mb_substr($source->name_bn.' (কপি)', 0, 160) : null;
            $copy->slug = $this->slugs->generate($copy->name, $copy->name_bn);
            $copy->status = ProductStatus::Draft;
            $copy->published_at = null;
            $copy->rating_count = 0;
            $copy->view_count = 0;
            $copy->save();

            $copy->categories()->sync($source->categories->modelKeys());

            $this->skus->reserve([]);
            $variantMap = [];

            foreach ($source->variants as $variant) {
                /** @var ProductVariant $variant */
                $values = $variant->attributeValues->sortBy(static fn (ProductAttributeValue $v): int => $v->attribute->position * 1_000_000 + $v->attribute_id)->values()->all();

                $new = $variant->replicate(['sku', 'barcode', 'product_id']);
                $new->product_id = $copy->id;
                $new->sku = $this->skus->generate($copy->id, $values);
                $new->barcode = null;
                $new->save();

                foreach ($variant->attributeValues as $value) {
                    $new->attributeValues()->attach($value->id, ['attribute_id' => $value->attribute_id]);
                }

                $variantMap[$variant->id] = $new->id;
            }

            foreach ($source->images as $image) {
                $new = $image->replicate(['product_id', 'variant_id']);
                $new->product_id = $copy->id;
                $new->variant_id = $image->variant_id !== null ? ($variantMap[$image->variant_id] ?? null) : null;
                $new->save();
            }

            foreach ($source->productRelations as $relation) {
                if ($relation->is_auto) {
                    continue;
                }

                $new = $relation->replicate(['product_id', 'created_by']);
                $new->product_id = $copy->id;
                $new->created_by = $actorId;
                $new->save();
            }

            ProductChanged::dispatch($copy->id, $copy->slug);

            return $copy;
        });
    }
}
