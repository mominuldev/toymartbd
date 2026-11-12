<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Storefront product page (P4-STO-05) + related row (P4-STO-07):
 * GET /api/v1/storefront/products/{slug}.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function pdp(string $slug, ?string $token = null): TestResponse
{
    $token ??= User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;

    return test()->withToken($token)->getJson('/api/v1/storefront/products/'.$slug);
}

/** Put `$units` on hand at the default location. */
function stock(ProductVariant $variant, int $units, int $reserved = 0): void
{
    DB::table('stock_levels')->updateOrInsert(
        ['variant_id' => $variant->id, 'location_id' => DB::table('stock_locations')->orderBy('id')->value('id')],
        ['on_hand' => $units, 'reserved' => $reserved, 'created_at' => now(), 'updated_at' => now()],
    );
}

/** A live simple product with stock. */
function liveProduct(array $attributes = [], int $units = 10): Product
{
    $product = Product::factory()->create($attributes);
    stock($product->variants()->firstOrFail(), $units);

    return $product;
}

/**
 * The C1.2 LEGO shape: Set (3) × Packaging (2), the tub out of stock everywhere.
 *
 * @return array{product: Product, variants: array<string, ProductVariant>, values: array<string, ProductAttributeValue>}
 */
function legoProduct(): array
{
    $set = ProductAttribute::factory()->create(['name' => 'Set', 'name_bn' => 'সেট', 'slug' => 'set', 'position' => 1]);
    $packaging = ProductAttribute::factory()->create(['name' => 'Packaging', 'name_bn' => 'প্যাকিং', 'slug' => 'packaging', 'position' => 2]);
    $values = [];
    foreach (['90-pcs' => 1, '484-pcs' => 2] as $slug => $position) {
        $values[$slug] = ProductAttributeValue::factory()->create(['attribute_id' => $set->id, 'slug' => $slug, 'value' => str_replace('-', ' ', $slug), 'position' => $position]);
    }
    foreach (['box' => 1, 'tub' => 2] as $slug => $position) {
        $values[$slug] = ProductAttributeValue::factory()->create(['attribute_id' => $packaging->id, 'slug' => $slug, 'value' => ucfirst($slug), 'position' => $position]);
    }

    $brand = Brand::factory()->create(['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego']);
    $product = Product::factory()->variable()->create(['slug' => 'lego-classic', 'name' => 'LEGO Classic', 'brand_id' => $brand->id]);

    $variants = [];
    $position = 0;
    foreach (['90-pcs' => ['1450.00', null], '484-pcs' => ['4250.00', '4990.00']] as $setSlug => [$price, $compareAt]) {
        foreach (['box', 'tub'] as $pack) {
            $variant = ProductVariant::factory()->for($product)->create([
                'price' => $price, 'compare_at_price' => $compareAt, 'position' => $position++,
                'is_default' => $setSlug === '484-pcs' && $pack === 'box', 'weight_grams' => 1450,
            ]);
            // Attach in reverse attribute order: the response must still list Set first.
            $variant->attributeValues()->attach($values[$pack]->id, ['attribute_id' => $packaging->id]);
            $variant->attributeValues()->attach($values[$setSlug]->id, ['attribute_id' => $set->id]);
            $variants["{$setSlug}/{$pack}"] = $variant;
        }
    }

    stock($variants['90-pcs/box'], 12);
    stock($variants['484-pcs/box'], 7, reserved: 4); // 3 available → low stock
    // Tubs: no stock rows at all → 0 available.

    return ['product' => $product, 'variants' => $variants, 'values' => $values];
}

it('returns the product page in exactly the agreed shape', function (): void {
    ['product' => $product, 'variants' => $variants, 'values' => $values] = legoProduct();
    $root = Category::factory()->create(['name' => 'Building', 'slug' => 'building']);
    $leaf = Category::factory()->create(['name' => 'LEGO sets', 'name_bn' => 'লেগো সেট', 'slug' => 'lego-sets', 'parent_id' => $root->id]);
    $product->update(['primary_category_id' => $leaf->id, 'specifications' => ['Pieces' => '484', 'Material' => 'ABS', 'Empty' => ' '], 'warranty_months' => 6]);

    $response = pdp('lego-classic')->assertOk();

    expect(array_keys($response->json()))->toBe([
        'id', 'slug', 'name', 'name_bn', 'type', 'brand', 'breadcrumbs', 'short_description', 'short_description_bn', 'description',
        'description_bn', 'specifications', 'age_min_months', 'age_max_months', 'gender', 'is_educational', 'safety_note', 'safety_note_bn',
        'warranty_months', 'rating_avg', 'rating_count', 'in_stock', 'default_variant_id', 'images', 'attributes', 'variants', 'related',
    ])
        ->and($response->json('brand'))->toBe(['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego'])
        ->and($response->json('breadcrumbs.*.slug'))->toBe(['building', 'lego-sets'])
        ->and($response->json('specifications'))->toBe([['key' => 'Pieces', 'value' => '484'], ['key' => 'Material', 'value' => 'ABS']])
        ->and($response->json('default_variant_id'))->toBe($variants['484-pcs/box']->id)
        ->and($response->json('in_stock'))->toBeTrue()
        ->and($response->json('attributes.*.slug'))->toBe(['set', 'packaging'])
        ->and($response->json('attributes.0.values.*.slug'))->toBe(['90-pcs', '484-pcs'])
        ->and($response->json('attributes.1.type'))->toBe(AttributeType::Select->value)
        ->and(array_keys($response->json('variants.0')))->toBe([
            'id', 'sku', 'name', 'name_bn', 'attribute_value_ids', 'price', 'compare_at_price', 'discount_percent', 'saving',
            'weight_grams', 'is_default', 'status', 'available', 'stock_state',
        ])
        ->and($response->json('variants.0.attribute_value_ids'))->toBe([$values['90-pcs']->id, $values['box']->id]);
});

it('computes variant prices, discounts and savings server-side', function (): void {
    ['variants' => $variants] = legoProduct();

    $byId = collect(pdp('lego-classic')->json('variants'))->keyBy('id');

    expect($byId[$variants['484-pcs/box']->id])->toMatchArray([
        'price' => '4250.00', 'compare_at_price' => '4990.00', 'discount_percent' => 14, 'saving' => '740.00', // 740/4990 = 14.8 % → 14
    ])->and($byId[$variants['90-pcs/box']->id])->toMatchArray([
        'price' => '1450.00', 'compare_at_price' => null, 'discount_percent' => null, 'saving' => null,
    ]);
});

it('drops a was-price that is not above the price', function (): void {
    $product = Product::factory()->withoutVariants()->create(['slug' => 'flat']);
    stock(ProductVariant::factory()->for($product)->create(['price' => '500.00', 'compare_at_price' => '450.00']), 3);

    expect(pdp('flat')->json('variants.0'))->toMatchArray(['compare_at_price' => null, 'discount_percent' => null, 'saving' => null]);
});

it('reports available units and the stock state per variant', function (): void {
    ['variants' => $variants] = legoProduct();

    $byId = collect(pdp('lego-classic')->json('variants'))->keyBy('id');

    expect($byId[$variants['90-pcs/box']->id])->toMatchArray(['available' => 12, 'stock_state' => 'in_stock'])
        ->and($byId[$variants['484-pcs/box']->id])->toMatchArray(['available' => 3, 'stock_state' => 'low_stock'])
        ->and($byId[$variants['484-pcs/tub']->id])->toMatchArray(['available' => 0, 'stock_state' => 'out_of_stock']);
});

it('says a product with no stock anywhere is not in stock, but still serves its page', function (): void {
    Product::factory()->create(['slug' => 'sold-out']);

    expect(pdp('sold-out')->assertOk()->json())->toMatchArray(['in_stock' => false])
        ->and(pdp('sold-out')->json('variants.0.stock_state'))->toBe('out_of_stock');
});

it('lists only active variants', function (): void {
    $product = Product::factory()->withoutVariants()->create(['slug' => 'two']);
    $active = ProductVariant::factory()->for($product)->create();
    ProductVariant::factory()->for($product)->archived()->create();

    expect(pdp('two')->json('variants.*.id'))->toBe([$active->id]);
});

it('is 404 for a draft, archived, scheduled, deleted, variant-less or unknown product', function (string $slug): void {
    Product::factory()->draft()->create(['slug' => 'draft']);
    Product::factory()->create(['slug' => 'archived', 'status' => ProductStatus::Archived]);
    Product::factory()->create(['slug' => 'scheduled', 'published_at' => now()->addDay()]);
    Product::factory()->create(['slug' => 'deleted'])->delete();
    $noActive = Product::factory()->withoutVariants()->create(['slug' => 'no-active']);
    ProductVariant::factory()->for($noActive)->archived()->create();

    pdp($slug)->assertNotFound()->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'not-found'));
})->with(['draft', 'archived', 'scheduled', 'deleted', 'no-active', 'nope']);

it('needs the storefront token', function (): void {
    liveProduct(['slug' => 'guarded']);

    test()->getJson('/api/v1/storefront/products/guarded')->assertUnauthorized();
    pdp('guarded', User::factory()->create()->createToken('other', ['something:else'])->plainTextToken)->assertForbidden();
});

it('renders images primary first with stage and thumb renditions, skipping unprocessed ones', function (): void {
    $product = liveProduct(['slug' => 'photos']);
    $ready = ['rendition_widths' => [160, 320, 480, 800, 1200], 'width' => 1600, 'height' => 1600];
    ProductImage::factory()->for($product)->create(['position' => 1, 'path' => 'products/1/second', ...$ready, 'alt_text' => 'Second', 'alt_text_bn' => null]);
    ProductImage::factory()->for($product)->create(['position' => 2, 'path' => null]);
    ProductImage::factory()->for($product)->create(['position' => 3, 'is_primary' => true, 'path' => 'products/1/main', ...$ready, 'alt_text' => 'Main', 'alt_text_bn' => 'মূল']);

    $images = pdp('photos')->json('images');

    expect($images)->toHaveCount(2)
        ->and($images[0]['alt'])->toBe('Main')
        ->and($images[0]['alt_bn'])->toBe('মূল')
        ->and($images[0]['url'])->toEndWith('/1200.webp')
        ->and($images[0]['thumb_url'])->toEndWith('/160.webp')
        ->and($images[0]['srcset_avif'])->toContain('800.avif 800w')
        ->and($images[1]['alt_bn'])->toBe('Second'); // falls back to the other language
});

it('orders related products curated first, then the same category, in stock only', function (): void {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $product = liveProduct(['slug' => 'main', 'primary_category_id' => $category->id]);

    $curatedB = liveProduct(['slug' => 'curated-b', 'primary_category_id' => $other->id]);
    $curatedA = liveProduct(['slug' => 'curated-a', 'primary_category_id' => $other->id]);
    $curatedSoldOut = liveProduct(['slug' => 'curated-sold-out', 'primary_category_id' => $other->id], units: 0);
    $curatedDraft = Product::factory()->draft()->create(['slug' => 'curated-draft']);
    $upsell = liveProduct(['slug' => 'upsell-only', 'primary_category_id' => $other->id]);
    foreach ([[$curatedA, 1], [$curatedB, 2], [$curatedSoldOut, 3], [$curatedDraft, 4]] as [$target, $position]) {
        ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $target->id, 'type' => RelationType::Related, 'position' => $position]);
    }
    ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $upsell->id, 'type' => RelationType::Upsell]);

    liveProduct(['slug' => 'sibling-old', 'primary_category_id' => $category->id, 'published_at' => now()->subDays(9)]);
    liveProduct(['slug' => 'sibling-new', 'primary_category_id' => $category->id, 'published_at' => now()->subDays(2)]);
    liveProduct(['slug' => 'sibling-featured', 'primary_category_id' => $category->id, 'is_featured' => true, 'published_at' => now()->subDays(30)]);
    liveProduct(['slug' => 'sibling-sold-out', 'primary_category_id' => $category->id], units: 0);
    Product::factory()->draft()->create(['slug' => 'sibling-draft', 'primary_category_id' => $category->id]);
    liveProduct(['slug' => 'elsewhere', 'primary_category_id' => $other->id]);

    $response = pdp('main');

    expect($response->json('related.*.slug'))->toBe(['curated-a', 'curated-b', 'sibling-featured', 'sibling-new', 'sibling-old'])
        ->and(array_keys($response->json('related.0')))->toContain('price', 'compare_at_price', 'discount_percent', 'image');
});

it('fills the row from the product\'s other categories after its primary one', function (): void {
    $parent = Category::factory()->create();
    $leaf = Category::factory()->create(['parent_id' => $parent->id]);
    $sibling = Category::factory()->create(['parent_id' => $parent->id]);
    $product = liveProduct(['slug' => 'main', 'primary_category_id' => $leaf->id]);
    $product->categories()->syncWithoutDetaching([$leaf->id, $parent->id]);
    $cousin = liveProduct(['slug' => 'cousin', 'primary_category_id' => $sibling->id]);
    $cousin->categories()->syncWithoutDetaching([$sibling->id, $parent->id]);
    liveProduct(['slug' => 'stranger', 'primary_category_id' => Category::factory()->create()->id]);

    expect(pdp('main')->json('related.*.slug'))->toBe(['cousin']);
});

it('caps the related row at 8 cards', function (): void {
    $category = Category::factory()->create();
    liveProduct(['slug' => 'main', 'primary_category_id' => $category->id]);
    foreach (range(1, 11) as $i) {
        liveProduct(['slug' => "sib-{$i}", 'primary_category_id' => $category->id]);
    }

    expect(pdp('main')->json('related'))->toHaveCount(8);
});

it('runs a fixed number of queries however many variants, images and related products there are (no N+1)', function (): void {
    $count = function (int $size): int {
        DB::flushQueryLog();
        $category = Category::factory()->create();
        $product = Product::factory()->variable()->create(['slug' => "p-{$size}", 'primary_category_id' => $category->id]);
        $axis = ProductAttribute::factory()->create();
        foreach (range(1, $size) as $i) {
            $value = ProductAttributeValue::factory()->create(['attribute_id' => $axis->id]);
            $variant = ProductVariant::factory()->for($product)->create(['position' => $i]);
            $variant->attributeValues()->attach($value->id, ['attribute_id' => $axis->id]);
            stock($variant, 5);
            ProductImage::factory()->for($product)->create(['path' => "products/x/{$i}", 'rendition_widths' => [160, 1200], 'position' => $i]);
            $sibling = liveProduct(['primary_category_id' => $category->id]);
            ProductImage::factory()->for($sibling)->create(['path' => "products/s/{$i}", 'rendition_widths' => [480], 'is_primary' => true]);
        }

        $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
        DB::enableQueryLog();
        DB::flushQueryLog();
        pdp("p-{$size}", $token)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Warm-up: the first request also authenticates the token (3 queries the guard then caches).
    pdp('warm-up')->assertNotFound();

    expect($count(2))->toBe($count(6));
});
