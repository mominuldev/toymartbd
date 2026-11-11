<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogFixtures;

/*
 * Admin products API (P4-CAT-01..03): /api/v1/admin/products.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/**
 * A valid simple-product document.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminProductPayload(array $overrides = []): array
{
    $category = Category::factory()->create();

    return [
        'name' => 'Wooden Train Set',
        'name_bn' => 'কাঠের ট্রেন সেট',
        'type' => 'simple',
        'status' => 'active',
        'primary_category_id' => $category->id,
        'category_ids' => [$category->id],
        'age_min_months' => 24,
        'age_max_months' => 72,
        'variants' => [['price' => '2450.00', 'compare_at_price' => '2900', 'cost_price' => '1600', 'weight_grams' => 1200]],
        ...$overrides,
    ];
}

/** @return list<string> */
function adminProductErrorFields(TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

it('creates a simple product and returns the Product shape', function (): void {
    $brand = Brand::factory()->create();

    $response = $this->postJson('/api/v1/admin/products', adminProductPayload(['brand_id' => $brand->id, 'gender' => 'unisex', 'specifications' => ['Material' => 'Pine wood']]));

    $response->assertCreated()
        ->assertJsonPath('name', 'Wooden Train Set')
        ->assertJsonPath('name_bn', 'কাঠের ট্রেন সেট')
        ->assertJsonPath('slug', 'wooden-train-set')
        ->assertJsonPath('type', 'simple')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('brand.id', $brand->id)
        ->assertJsonPath('gender', 'unisex')
        ->assertJsonPath('specifications.Material', 'Pine wood')
        ->assertJsonPath('options', [])
        ->assertJsonCount(1, 'variants')
        ->assertJsonPath('variants.0.price', '2450.00')
        ->assertJsonPath('variants.0.compare_at_price', '2900.00')
        ->assertJsonPath('variants.0.cost_price', '1600.00')
        ->assertJsonPath('variants.0.is_default', true)
        ->assertJsonPath('variants.0.attribute_values', []);

    expect($response->json('variants.0.sku'))->toBe(sprintf('TM-%05d', $response->json('id')))
        ->and($response->json('published_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('creates a variable product with a 3×4 matrix in one payload', function (): void {
    ['color' => $color, 'colors' => $colors, 'size' => $size, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();

    $response = $this->postJson('/api/v1/admin/products', adminProductPayload([
        'type' => 'variable',
        'options' => [
            ['attribute_id' => $color->id, 'value_ids' => array_map(fn ($v) => $v->id, $colors)],
            ['attribute_id' => $size->id, 'value_ids' => array_map(fn ($v) => $v->id, $sizes)],
        ],
        'variant_defaults' => ['price' => '1450', 'cost_price' => '900', 'weight_grams' => 300],
        'variants' => [['attribute_value_ids' => [$colors[1]->id, $sizes[3]->id], 'price' => '1650', 'sku' => 'RAIN-BLUE-XL', 'barcode' => '8801234567890']],
    ]))->assertCreated();

    $response->assertJsonCount(12, 'variants')
        ->assertJsonCount(2, 'options')
        ->assertJsonPath('variants.0.name', 'Red / Small')
        ->assertJsonPath('variants.0.price', '1450.00');

    $blueXl = collect($response->json('variants'))->firstWhere('sku', 'RAIN-BLUE-XL');
    expect($blueXl['price'])->toBe('1650.00')
        ->and($blueXl['barcode'])->toBe('8801234567890')
        ->and($blueXl['attribute_values'])->toHaveCount(2);
});

it('updates the matrix through PUT: removed values archive, ids and SKUs survive', function (): void {
    ['color' => $color, 'colors' => $colors, 'size' => $size, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();
    $payload = adminProductPayload([
        'type' => 'variable',
        'options' => [
            ['attribute_id' => $color->id, 'value_ids' => [$colors[0]->id, $colors[1]->id]],
            ['attribute_id' => $size->id, 'value_ids' => [$sizes[0]->id]],
        ],
        'variant_defaults' => ['price' => '500'],
        'variants' => [],
    ]);
    $created = $this->postJson('/api/v1/admin/products', $payload)->assertCreated();
    $id = $created->json('id');
    $redSku = $created->json('variants.0.sku');

    $payload['options'][0]['value_ids'] = [$colors[0]->id];
    $updated = $this->putJson("/api/v1/admin/products/{$id}", $payload)->assertOk();

    $updated->assertJsonCount(2, 'variants')
        ->assertJsonPath('variants.0.sku', $redSku)
        ->assertJsonPath('variants.0.status', 'active')
        ->assertJsonPath('variants.1.status', 'archived')
        ->assertJsonPath('options.0.value_ids', [$colors[0]->id]);
});

it('shows a product with variants, attribute values, images, categories and relations', function (): void {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/train.jpg', 'is_primary' => true]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => null, 'position' => 1]);
    App\Domain\Catalog\Models\ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $other->id]);

    $this->getJson("/api/v1/admin/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('id', $product->id)
        ->assertJsonPath('category_ids', [$product->primary_category_id])
        ->assertJsonPath('categories.0.id', $product->primary_category_id)
        ->assertJsonCount(1, 'variants')
        ->assertJsonCount(2, 'images')
        ->assertJsonPath('images.1.url', null)
        ->assertJsonPath('relations.0.related_product.id', $other->id)
        ->assertJsonPath('relations.0.type', 'related')
        ->assertJsonPath('relations.0.is_auto', false);
});

it('returns a not-found problem for an unknown product', function (): void {
    $this->getJson('/api/v1/admin/products/999999')
        ->assertNotFound()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
});

it('rejects invalid documents with a validation-error problem', function (array $overrides, string $field): void {
    Product::factory()->create(['slug' => 'taken']);

    $response = $this->postJson('/api/v1/admin/products', adminProductPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');

    expect(adminProductErrorFields($response))->toContain($field);
})->with([
    'missing name' => [['name' => null], 'name'],
    'unknown type' => [['type' => 'bundle'], 'type'],
    'unknown status' => [['status' => 'live'], 'status'],
    'no categories' => [['category_ids' => []], 'category_ids'],
    'primary not among categories' => [['category_ids' => [999999]], 'primary_category_id'],
    'duplicate slug' => [['slug' => 'taken'], 'slug'],
    'Bangla slug' => [['slug' => 'ট্রেন'], 'slug'],
    'money as a number' => [['variants' => [['price' => 2450]]], 'variants.0.price'],
    'negative money' => [['variants' => [['price' => '-5']]], 'variants.0.price'],
    'three decimals' => [['variants' => [['price' => '1.005']]], 'variants.0.price'],
    'compare-at not above price' => [['variants' => [['price' => '100', 'compare_at_price' => '100']]], 'variants.0.compare_at_price'],
    'bad SKU' => [['variants' => [['price' => '100', 'sku' => 'has space']]], 'variants.0.sku'],
    'bad gender' => [['gender' => 'kids'], 'gender'],
    'age too high' => [['age_max_months' => 500], 'age_max_months'],
    'unsluggable name' => [['name' => '!!!', 'name_bn' => null], 'slug'],
    'unknown relation type' => [['relations' => [['related_product_id' => 1, 'type' => 'similar']]], 'relations.0.type'],
]);

it('hides cost_price from staff without catalog.view_cost', function (): void {
    $product = Product::factory()->create();
    auth()->forgetGuards();
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');

    $variant = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json('variants.0');

    expect($variant)->not->toHaveKey('cost_price')
        ->and($variant['price'])->toBe('1250.00');
});

it('lets a content editor save copy with unchanged prices, but not change a price or send cost', function (): void {
    $created = $this->postJson('/api/v1/admin/products', adminProductPayload())->assertCreated();
    $id = $created->json('id');
    $document = adminProductPayload(['primary_category_id' => $created->json('primary_category_id'), 'category_ids' => $created->json('category_ids')]);
    unset($document['variants'][0]['cost_price']);

    auth()->forgetGuards();
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');

    $this->putJson("/api/v1/admin/products/{$id}", [...$document, 'name' => 'Wooden Train Set Deluxe'])
        ->assertOk()
        ->assertJsonPath('name', 'Wooden Train Set Deluxe');

    $reprice = $document;
    $reprice['variants'][0]['price'] = '1999';
    $this->putJson("/api/v1/admin/products/{$id}", $reprice)->assertForbidden()->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden');

    $cost = $document;
    $cost['variants'][0]['cost_price'] = '1';
    $this->putJson("/api/v1/admin/products/{$id}", $cost)->assertForbidden();

    $variant = ProductVariant::query()->where('product_id', $id)->sole();
    expect($variant->price->toDecimal())->toBe('2450.00')
        ->and($variant->cost_price->toDecimal())->toBe('1600.00');
});

it('archives on DELETE, idempotently, and restores through PUT', function (): void {
    $created = $this->postJson('/api/v1/admin/products', adminProductPayload())->assertCreated();
    $id = $created->json('id');

    $this->deleteJson("/api/v1/admin/products/{$id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/products/{$id}")->assertNoContent();

    expect(Product::query()->find($id)?->status)->toBe(ProductStatus::Archived)
        ->and(ProductVariant::query()->where('product_id', $id)->sole()->status)->toBe(VariantStatus::Active);

    $this->getJson('/api/v1/admin/products?filter[status]=archived')->assertJsonPath('data.0.id', $id);
});

it('lists products newest-updated first with price range and filters', function (): void {
    $brand = Brand::factory()->create();
    $category = Category::factory()->create();
    $old = Product::factory()->priced('300.00')->create(['name' => 'Alpha Blocks', 'brand_id' => $brand->id, 'updated_at' => now()->subDay()]);
    $new = Product::factory()->create(['name' => 'Beta Doll', 'name_bn' => 'বেটা পুতুল']);
    $new->categories()->attach($category->id);
    ProductVariant::factory()->create(['product_id' => $new->id, 'price' => '2000.00', 'sku' => 'BETA-XL']);
    $draft = Product::factory()->draft()->create(['name' => 'Gamma Kite']);

    $this->getJson('/api/v1/admin/products')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('data.*.id', [$draft->id, $new->id, $old->id]);

    $this->getJson('/api/v1/admin/products?sort=name')->assertJsonPath('data.*.id', [$old->id, $new->id, $draft->id]);

    $row = collect($this->getJson('/api/v1/admin/products')->json('data'))->firstWhere('id', $new->id);
    expect($row['price_min'])->toBe('1250.00')
        ->and($row['price_max'])->toBe('2000.00')
        ->and($row['variants_count'])->toBe(2)
        ->and($row['sku'])->toBe(sprintf('TM-%05d', $new->id));

    $this->getJson('/api/v1/admin/products?filter[status]=draft')->assertJsonPath('data.*.id', [$draft->id]);
    $this->getJson('/api/v1/admin/products?filter[brand_id]='.$brand->id)->assertJsonPath('data.*.id', [$old->id]);
    $this->getJson('/api/v1/admin/products?filter[category_id]='.$category->id)->assertJsonPath('data.*.id', [$new->id]);
    $this->getJson('/api/v1/admin/products?filter[type]=variable')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/products?filter[search]=blocks')->assertJsonPath('data.*.id', [$old->id]);
    $this->getJson('/api/v1/admin/products?filter[search]='.urlencode('পুতুল'))->assertJsonPath('data.*.id', [$new->id]);
    $this->getJson('/api/v1/admin/products?filter[search]=beta-x')->assertJsonPath('data.*.id', [$new->id]);
});

it('rejects an unlisted filter or sort with a bad-request problem', function (string $query): void {
    $this->getJson('/api/v1/admin/products?'.$query)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unknown filter' => ['filter[price]=100'],
    'unknown sort' => ['sort=price'],
    'unknown status' => ['filter[status]=deleted'],
    'unknown type' => ['filter[type]=bundle'],
    'non-numeric brand' => ['filter[brand_id]=lego'],
]);

it('cursor-paginates 25 per page', function (): void {
    foreach (range(1, 27) as $i) {
        Product::factory()->create(['name' => sprintf('Product %02d', $i)]);
    }

    $first = $this->getJson('/api/v1/admin/products?sort=name')->assertOk()->assertJsonCount(25, 'data');
    $second = $this->getJson('/api/v1/admin/products?sort=name&cursor='.$first->json('meta.next_cursor'))->assertOk()->assertJsonCount(2, 'data');

    expect($second->json('data.0.name'))->toBe('Product 26')
        ->and($second->json('meta.next_cursor'))->toBeNull();
});

it('duplicates through the API and returns the draft copy', function (): void {
    $id = $this->postJson('/api/v1/admin/products', adminProductPayload())->assertCreated()->json('id');

    $copy = $this->postJson("/api/v1/admin/products/{$id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('name', 'Wooden Train Set (copy)')
        ->assertJsonPath('variants.0.price', '2450.00');

    expect($copy->json('slug'))->toBe('wooden-train-set-copy')
        ->and($copy->json('variants.0.sku'))->toBe(sprintf('TM-%05d', $copy->json('id')));
});
