<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The export side of the product spreadsheet (P4-CAT-10): one row per variant, in the
 * columns of {@see ProductSpreadsheet::columns()}, for the products of a query the
 * caller has already filtered (the admin list's filters). Archived variants are
 * included with `variant_status = archived`, so a re-import never revives one.
 * Values are written exactly as stored — money as its DECIMAL string, never a float.
 * `cost_price` is present only when the caller may see cost. Rows come in product id
 * order (chunked by id), whatever the list's sort.
 */
final class ProductExportRows
{
    /** Products are read in chunks of this many. */
    private const int CHUNK = 200;

    /**
     * @param  Builder<Product>  $products
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(Builder $products, bool $withCost): Generator
    {
        $query = $products->clone()->reorder()
            ->with(['brand:id,slug', 'primaryCategory:id,slug', 'categories:id,slug', 'variants']);

        foreach ($query->lazyById(self::CHUNK, 'products.id', 'id') as $product) {
            /** @var Product $product */
            $variants = $product->variants->sortBy([['position', 'asc'], ['id', 'asc']]);

            foreach ($variants as $variant) {
                /** @var ProductVariant $variant */
                yield $this->row($product, $variant, $withCost);
            }
        }
    }

    /** @return array<string, string|null> */
    private function row(Product $product, ProductVariant $variant, bool $withCost): array
    {
        $bool = static fn (bool $v): string => $v ? '1' : '0';
        $int = static fn (?int $v): ?string => $v === null ? null : (string) $v;

        $row = [
            'sku' => $variant->sku,
            'type' => $product->type->value,
            'name' => $product->name,
            'name_bn' => $product->name_bn,
            'slug' => $product->slug,
            'status' => $product->status->value,
            'brand' => $product->brand?->slug,
            'primary_category' => $product->primaryCategory->slug,
            'categories' => implode(ProductSpreadsheet::LIST_SEPARATOR, $product->categories
                ->sortBy(static fn (Category $c): int => $c->id === $product->primary_category_id ? 0 : 1)
                ->pluck('slug')->all()),
            'short_description' => $product->short_description,
            'short_description_bn' => $product->short_description_bn,
            'description' => $product->description,
            'description_bn' => $product->description_bn,
            'age_min_months' => $int($product->age_min_months),
            'age_max_months' => $int($product->age_max_months),
            'gender' => $product->gender?->value,
            'is_educational' => $bool($product->is_educational),
            'is_featured' => $bool($product->is_featured),
            'warranty_months' => $int($product->warranty_months),
            'safety_note' => $product->safety_note,
            'safety_note_bn' => $product->safety_note_bn,
            'vat_rate' => $product->vat_rate,
            'variant_name' => $variant->name,
            'barcode' => $variant->barcode,
            'price' => $variant->price->toDecimal(),
            'compare_at_price' => $variant->compare_at_price?->toDecimal(),
            'wholesale_price' => $variant->wholesale_price?->toDecimal(),
            'weight_grams' => (string) $variant->weight_grams,
            'variant_status' => $variant->status->value,
        ];

        if ($withCost) {
            $row['cost_price'] = $variant->cost_price->toDecimal();
        }

        return $row;
    }
}
