<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixtures;

/*
 * P4-CAT-01/02 — product schema, relations, scopes and casts.
 */

it('creates the product tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['products', 'product_variants', 'variant_attribute_values', 'category_product', 'product_images', 'product_relations']);

it('keys variant_attribute_values on (variant_id, attribute_id)', function (): void {
    $primary = collect(Schema::getIndexes('variant_attribute_values'))->firstWhere('primary', true);

    expect($primary['columns'] ?? null)->toBe(['variant_id', 'attribute_id']);
});

it('keeps product_relations unique on (product, related, type)', function (): void {
    $relation = ProductRelation::factory()->create();

    expect(fn () => ProductRelation::factory()->create([
        'product_id' => $relation->product_id,
        'related_product_id' => $relation->related_product_id,
        'type' => $relation->type,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('reads every money column as Money and serialises it as a string', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '1250.5', 'compare_at_price' => '1500', 'wholesale_price' => null])->refresh();

    expect($variant->price)->toBeInstanceOf(Money::class)
        ->and($variant->price->toDecimal())->toBe('1250.50')
        ->and($variant->compare_at_price?->toDecimal())->toBe('1500.00')
        ->and($variant->cost_price->toDecimal())->toBe('800.00')
        ->and($variant->wholesale_price)->toBeNull();

    $variant->price = '1250.50';
    expect($variant->isDirty('price'))->toBeFalse();
});

it('wires the product relations', function (): void {
    ['colors' => $colors] = CatalogFixtures::colorAndSize();
    $brand = Brand::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create(['brand_id' => $brand->id, 'primary_category_id' => $category->id]);
    $variant = $product->defaultVariant()->sole();
    $variant->attributeValues()->attach($colors[0]->id, ['attribute_id' => $colors[0]->attribute_id]);
    ProductImage::factory()->create(['product_id' => $product->id, 'variant_id' => $variant->id]);
    $other = Product::factory()->create();
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $other->id, 'type' => RelationType::AgeProgression]);

    $product->refresh();

    expect($product->brand?->is($brand))->toBeTrue()
        ->and($product->primaryCategory->is($category))->toBeTrue()
        ->and($product->categories->modelKeys())->toBe([$category->id])
        ->and($product->variants)->toHaveCount(1)
        ->and($product->defaultVariant?->is($variant))->toBeTrue()
        ->and($variant->product->is($product))->toBeTrue()
        ->and($variant->attributeValues->first()?->is($colors[0]))->toBeTrue()
        ->and($product->images->first()?->variant?->is($variant))->toBeTrue()
        ->and($product->images->first()?->path)->toBeNull()
        ->and($product->productRelations->first()?->relatedProduct->is($other))->toBeTrue()
        ->and($product->productRelations->first()?->type)->toBe(RelationType::AgeProgression);
});

it('scopes active and published products', function (): void {
    $live = Product::factory()->create();
    $scheduled = Product::factory()->create(['published_at' => now()->addDay()]);
    Product::factory()->draft()->create();
    Product::factory()->archived()->create();

    expect(Product::query()->active()->pluck('id')->sort()->values()->all())->toBe([$live->id, $scheduled->id])
        ->and(Product::query()->published()->pluck('id')->all())->toBe([$live->id]);
});

it('scopes active variants and curated relations', function (): void {
    $product = Product::factory()->create();
    ProductVariant::factory()->archived()->create(['product_id' => $product->id]);
    ProductRelation::factory()->create(['product_id' => $product->id, 'is_auto' => true]);
    ProductRelation::factory()->create(['product_id' => $product->id]);

    expect($product->variants()->count())->toBe(2)
        ->and($product->activeVariants()->count())->toBe(1)
        ->and(ProductVariant::query()->active()->where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductRelation::query()->curated()->where('product_id', $product->id)->count())->toBe(1);
});

it('soft-deletes products but keeps the slug reserved', function (): void {
    $product = Product::factory()->create(['slug' => 'gone']);
    $product->delete();

    expect(Product::query()->count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(1)
        ->and(app(App\Domain\Catalog\Services\ProductSlugs::class)->generate('Gone', null))->toBe('gone-2');
});
