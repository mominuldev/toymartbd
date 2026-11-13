<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Storefront product listings (P4-CAT-13): GET /api/v1/storefront/products.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function listingToken(array $abilities = ['storefront:read']): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', $abilities)->plainTextToken;
}

/** @param  array<string, string>  $query */
function listing(array $query = [], ?string $token = null): TestResponse
{
    $uri = '/api/v1/storefront/products'.($query === [] ? '' : '?'.http_build_query($query));

    return test()->withToken($token ?? listingToken())->getJson($uri);
}

/** @param  array<string, mixed>  $attributes */
function listed(array $attributes = [], string $price = '1250.00'): Product
{
    if (isset($attributes['slug']) && ! isset($attributes['name'])) {
        $attributes['name'] = $attributes['slug'];
    }

    return Product::factory()->priced($price)->create($attributes);
}

/** @return list<string> */
function slugs(TestResponse $response): array
{
    return $response->json('data.*.slug');
}

/** @return array<string, int> facet value => count */
function facetCounts(TestResponse $response, string $facet): array
{
    $out = [];
    foreach ($response->json("meta.facets.{$facet}") as $row) {
        $out[(string) $row['value']] = $row['count'];
    }

    return $out;
}

it('returns cards in exactly the agreed shape', function (): void {
    $brand = Brand::factory()->create(['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego']);
    $product = Product::factory()->variable()->create([
        'name' => 'Rainbow Stacker', 'name_bn' => 'রংধনু স্ট্যাকার', 'slug' => 'rainbow-stacker', 'brand_id' => $brand->id,
        'age_min_months' => 12, 'age_max_months' => 36, 'is_featured' => true,
    ]);
    ProductVariant::factory()->for($product)->create(['price' => '1500.00', 'compare_at_price' => '1800.00', 'position' => 0]);
    ProductVariant::factory()->for($product)->create(['price' => '990.50', 'compare_at_price' => '1200.00', 'position' => 1]);
    ProductVariant::factory()->for($product)->archived()->create(['price' => '10.00', 'position' => 2]);

    $response = listing()->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'links', 'meta'])
        ->and(array_keys($response->json('data.0')))->toBe([
            'id', 'slug', 'name', 'name_bn', 'brand', 'price', 'compare_at_price', 'price_max', 'discount_percent', 'age_min_months',
            'age_max_months', 'rating_avg', 'rating_count', 'is_featured', 'variant_count', 'in_stock', 'image', 'flash_sale',
        ])
        ->and($response->json('data.0'))->toMatchArray([
            'slug' => 'rainbow-stacker',
            'name_bn' => 'রংধনু স্ট্যাকার',
            'brand' => ['name' => 'LEGO', 'name_bn' => 'লেগো', 'slug' => 'lego'],
            'price' => '990.50',
            'compare_at_price' => '1200.00',
            'price_max' => '1500.00',
            'discount_percent' => 17, // (1200 - 990.50) / 1200 = 17.46 %, rounded down
            'age_min_months' => 12,
            'age_max_months' => 36,
            'rating_avg' => null,
            'rating_count' => 0,
            'is_featured' => true,
            'variant_count' => 2,
            'image' => null,
        ])
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.per_page'))->toBe(24)
        ->and($response->json('meta.sort'))->toBe('featured')
        ->and(array_keys($response->json('meta.facets')))->toBe(['category', 'brand', 'age_band', 'price_band', 'gender', 'rating', 'educational', 'availability']);
});

it('drops a was-price that is not above the price', function (): void {
    $product = Product::factory()->withoutVariants()->create();
    ProductVariant::factory()->for($product)->create(['price' => '500.00', 'compare_at_price' => '500.00']);

    expect(listing()->json('data.0.compare_at_price'))->toBeNull()
        ->and(listing()->json('data.0.discount_percent'))->toBeNull();
});

it('renders the primary image with srcsets and both alts, or null without a file', function (): void {
    $withFile = listed(['slug' => 'with-file']);
    ProductImage::factory()->for($withFile)->create(['position' => 1, 'path' => null]);
    ProductImage::factory()->for($withFile)->create([
        'position' => 2, 'is_primary' => true, 'path' => 'products/1/abc', 'width' => 1200, 'height' => 1200,
        'rendition_widths' => [160, 320, 480, 800, 1200], 'alt_text' => 'A stacker', 'alt_text_bn' => 'একটি স্ট্যাকার',
    ]);
    $seeded = listed(['slug' => 'seeded']);
    ProductImage::factory()->for($seeded)->create(['path' => null]);

    $response = listing(['sort' => 'name'])->assertOk();
    $cards = collect($response->json('data'))->keyBy('slug');

    expect($cards['seeded']['image'])->toBeNull()
        ->and($cards['with-file']['image'])->toMatchArray(['alt' => 'A stacker', 'alt_bn' => 'একটি স্ট্যাকার', 'width' => 1200, 'height' => 1200])
        ->and($cards['with-file']['image']['url'])->toEndWith('products/1/abc/480.webp')
        ->and($cards['with-file']['image']['srcset_webp'])->toContain('160.webp 160w')
        ->and($cards['with-file']['image']['srcset_avif'])->toContain('1200.avif 1200w');
});

it('never lists draft, archived, unpublished, deleted or variant-less products', function (): void {
    listed(['slug' => 'live']);
    Product::factory()->draft()->create(['slug' => 'draft']);
    Product::factory()->archived()->create(['slug' => 'archived']);
    listed(['slug' => 'scheduled', 'published_at' => now()->addDay()]);
    listed(['slug' => 'deleted'])->delete();
    Product::factory()->withoutVariants()->create(['slug' => 'no-variants']);
    $onlyArchived = Product::factory()->withoutVariants()->create(['slug' => 'only-archived-variants']);
    ProductVariant::factory()->for($onlyArchived)->archived()->create();

    $response = listing()->assertOk();

    expect(slugs($response))->toBe(['live'])->and($response->json('meta.total'))->toBe(1);
});

it('filters by category including descendants', function (): void {
    $toys = Category::factory()->create(['slug' => 'toys']);
    $puzzles = Category::factory()->childOf($toys)->create(['slug' => 'puzzles']);
    $jigsaw = Category::factory()->childOf($puzzles)->create(['slug' => 'jigsaw']);
    $books = Category::factory()->create(['slug' => 'books']);
    listed(['slug' => 'a', 'primary_category_id' => $toys->id]);
    listed(['slug' => 'b', 'primary_category_id' => $jigsaw->id]);
    listed(['slug' => 'c', 'primary_category_id' => $books->id]);
    // Secondary membership counts too.
    listed(['slug' => 'd', 'primary_category_id' => $books->id])->categories()->attach($puzzles->id);

    expect(slugs(listing(['filter[category]' => 'toys', 'sort' => 'name'])))->toBe(['a', 'b', 'd'])
        ->and(slugs(listing(['filter[category]' => 'puzzles', 'sort' => 'name'])))->toBe(['b', 'd'])
        ->and(slugs(listing(['filter[category]' => 'jigsaw'])))->toBe(['b'])
        ->and(slugs(listing(['filter[category]' => 'unknown'])))->toBe([]);
});

it('filters by one or several brands', function (): void {
    $lego = Brand::factory()->create(['slug' => 'lego']);
    $hape = Brand::factory()->create(['slug' => 'hape']);
    listed(['slug' => 'a', 'brand_id' => $lego->id]);
    listed(['slug' => 'b', 'brand_id' => $hape->id]);
    listed(['slug' => 'c']);

    expect(slugs(listing(['filter[brand]' => 'lego'])))->toBe(['a'])
        ->and(slugs(listing(['filter[brand]' => 'lego,hape', 'sort' => 'name'])))->toBe(['a', 'b'])
        ->and(slugs(listing(['filter[brand]' => 'nobody'])))->toBe([]);
});

it('matches an age with inclusive bounds and open null bounds', function (int $age, array $expected): void {
    listed(['slug' => 'a-12-24', 'age_min_months' => 12, 'age_max_months' => 24]);
    listed(['slug' => 'b-open-top', 'age_min_months' => 36, 'age_max_months' => null]);
    listed(['slug' => 'c-open-bottom', 'age_min_months' => null, 'age_max_months' => 6]);
    listed(['slug' => 'd-any', 'age_min_months' => null, 'age_max_months' => null]);

    expect(slugs(listing(['filter[age]' => (string) $age, 'sort' => 'name'])))->toBe($expected);
})->with([
    'below min' => [11, ['d-any']],
    'at min' => [12, ['a-12-24', 'd-any']],
    'at max' => [24, ['a-12-24', 'd-any']],
    'above max' => [25, ['d-any']],
    'open top' => [200, ['b-open-top', 'd-any']],
    'open bottom' => [0, ['c-open-bottom', 'd-any']],
    'open bottom at max' => [6, ['c-open-bottom', 'd-any']],
]);

it('filters by age bands, any of', function (): void {
    listed(['slug' => 'baby', 'age_min_months' => 0, 'age_max_months' => 12]);
    listed(['slug' => 'pre', 'age_min_months' => 36, 'age_max_months' => 48]);
    listed(['slug' => 'teen', 'age_min_months' => 150, 'age_max_months' => null]);

    expect(slugs(listing(['filter[age_band]' => '12-24', 'sort' => 'name'])))->toBe(['baby'])
        ->and(slugs(listing(['filter[age_band]' => '24-48', 'sort' => 'name'])))->toBe(['pre'])
        ->and(slugs(listing(['filter[age_band]' => '48-72'])))->toBe(['pre'])
        ->and(slugs(listing(['filter[age_band]' => '0-12,144-', 'sort' => 'name'])))->toBe(['baby', 'teen']);
});

it('filters by the lowest active variant price, inclusive, and by price band', function (): void {
    listed(['slug' => 'a'], '499.99');
    listed(['slug' => 'b'], '500.00');
    listed(['slug' => 'c'], '2500.00');
    $variable = Product::factory()->withoutVariants()->create(['slug' => 'd']);
    ProductVariant::factory()->for($variable)->create(['price' => '6000.00']);
    ProductVariant::factory()->for($variable)->create(['price' => '900.00']);
    ProductVariant::factory()->for($variable)->archived()->create(['price' => '100.00']);

    expect(slugs(listing(['filter[price_min]' => '500', 'filter[price_max]' => '2500.00', 'sort' => 'price_asc'])))->toBe(['b', 'd', 'c'])
        ->and(slugs(listing(['filter[price_max]' => '499.99'])))->toBe(['a'])
        ->and(slugs(listing(['filter[price_band]' => '0-500'])))->toBe(['a'])
        ->and(slugs(listing(['filter[price_band]' => '500-1000,5000-', 'sort' => 'price_asc'])))->toBe(['b', 'd']);
});

it('filters by gender, where for-everyone includes products without one', function (): void {
    listed(['slug' => 'boys', 'gender' => Gender::Boys]);
    listed(['slug' => 'girls', 'gender' => Gender::Girls]);
    listed(['slug' => 'unisex', 'gender' => Gender::Unisex]);
    listed(['slug' => 'none', 'gender' => null]);

    expect(slugs(listing(['filter[gender]' => 'boys'])))->toBe(['boys'])
        ->and(slugs(listing(['filter[gender]' => 'unisex', 'sort' => 'name'])))->toBe(['none', 'unisex'])
        ->and(slugs(listing(['filter[gender]' => 'boys,girls', 'sort' => 'name'])))->toBe(['boys', 'girls']);
});

it('filters by minimum rating and by educational', function (): void {
    listed(['slug' => 'four', 'is_educational' => true])->forceFill(['rating_avg' => '4.00', 'rating_count' => 3])->save();
    listed(['slug' => 'three'])->forceFill(['rating_avg' => '3.90', 'rating_count' => 3])->save();
    listed(['slug' => 'none', 'is_educational' => true]);

    expect(slugs(listing(['filter[rating]' => '4'])))->toBe(['four'])
        ->and(slugs(listing(['filter[rating]' => '3', 'sort' => 'rating'])))->toBe(['four', 'three'])
        ->and(slugs(listing(['filter[educational]' => 'true', 'sort' => 'name'])))->toBe(['four', 'none'])
        ->and(slugs(listing(['filter[educational]' => '0'])))->toBe(['three']);
});

it('sorts every allowlisted way', function (string $sort, array $expected): void {
    $a = listed(['slug' => 'a', 'name' => 'Alpha', 'published_at' => now()->subDays(3), 'is_featured' => false], '300.00');
    $a->forceFill(['rating_avg' => '4.50', 'rating_count' => 2])->save();
    $b = listed(['slug' => 'b', 'name' => 'Bravo', 'published_at' => now()->subDays(2), 'is_featured' => true], '100.00');
    $b->forceFill(['rating_avg' => null])->save();
    $c = listed(['slug' => 'c', 'name' => 'Charlie', 'published_at' => now()->subDay(), 'is_featured' => false], '200.00');
    $c->forceFill(['rating_avg' => '3.00', 'rating_count' => 9])->save();

    $response = listing(['sort' => $sort])->assertOk();

    expect(slugs($response))->toBe($expected)->and($response->json('meta.sort'))->toBe($sort);
})->with([
    'featured' => ['featured', ['b', 'c', 'a']],
    'newest' => ['newest', ['c', 'b', 'a']],
    'price_asc' => ['price_asc', ['b', 'c', 'a']],
    'price_desc' => ['price_desc', ['a', 'c', 'b']],
    'rating' => ['rating', ['a', 'c', 'b']],
    'name' => ['name', ['a', 'b', 'c']],
]);

it('paginates 24 per page by cursor without gaps or repeats, in every sort', function (string $sort): void {
    foreach (range(1, 30) as $i) {
        $product = listed(['slug' => sprintf('p-%02d', $i), 'is_featured' => $i % 3 === 0, 'published_at' => now()->subHours($i % 5)], (string) (100 * ($i % 7) + 100));
        if ($i % 2 === 0) {
            $product->forceFill(['rating_avg' => '4.00'])->save();
        }
    }

    $first = listing(['sort' => $sort])->assertOk();
    expect($first->json('data'))->toHaveCount(24)->and($first->json('meta.next_cursor'))->toBeString();

    $second = listing(['sort' => $sort, 'cursor' => $first->json('meta.next_cursor')])->assertOk();
    $all = [...slugs($first), ...slugs($second)];

    expect($second->json('data'))->toHaveCount(6)
        ->and($second->json('meta.next_cursor'))->toBeNull()
        ->and(count(array_unique($all)))->toBe(30)
        ->and($second->json('meta.total'))->toBe(30);
})->with(['featured', 'newest', 'price_asc', 'price_desc', 'rating', 'name']);

it('computes disjunctive facet counts that match the results', function (): void {
    $toys = Category::factory()->create(['slug' => 'toys']);
    $puzzles = Category::factory()->childOf($toys)->create(['slug' => 'puzzles', 'name' => 'Puzzles', 'name_bn' => 'পাজল']);
    $blocks = Category::factory()->childOf($toys)->create(['slug' => 'blocks']);
    Category::factory()->childOf($toys)->create(['slug' => 'empty']);
    $lego = Brand::factory()->create(['slug' => 'lego', 'name' => 'LEGO']);
    $hape = Brand::factory()->create(['slug' => 'hape', 'name' => 'Hape']);

    $rows = [
        ['lego', $puzzles, 12, 24, Gender::Boys, '400.00', '4.20', true],
        ['lego', $blocks, 36, 72, null, '1200.00', null, false],
        ['hape', $puzzles, 0, 12, Gender::Girls, '800.00', '3.10', true],
        ['hape', $blocks, 48, null, Gender::Unisex, '6000.00', '4.90', false],
        [null, $puzzles, 24, 48, Gender::Boys, '2500.00', '2.00', false],
    ];
    foreach ($rows as $i => [$brand, $category, $min, $max, $gender, $price, $rating, $edu]) {
        listed([
            'slug' => "p{$i}", 'brand_id' => $brand === null ? null : ($brand === 'lego' ? $lego->id : $hape->id),
            'primary_category_id' => $category->id, 'age_min_months' => $min, 'age_max_months' => $max,
            'gender' => $gender, 'is_educational' => $edu,
        ], $price)->forceFill(['rating_avg' => $rating])->save();
    }

    $base = ['filter[category]' => 'toys', 'filter[brand]' => 'lego'];
    $response = listing($base)->assertOk();

    // Brand ignores its own filter: what each brand would add.
    expect(facetCounts($response, 'brand'))->toBe(['hape' => 2, 'lego' => 2])
        ->and(collect($response->json('meta.facets.brand'))->firstWhere('value', 'lego')['selected'])->toBeTrue()
        // Every other facet is counted within LEGO.
        ->and(facetCounts($response, 'category'))->toBe(['puzzles' => 1, 'blocks' => 1, 'empty' => 0])
        ->and(collect($response->json('meta.facets.category'))->firstWhere('value', 'puzzles')['label_bn'])->toBe('পাজল')
        ->and(facetCounts($response, 'age_band'))->toBe(['0-12' => 0, '12-24' => 1, '24-48' => 2, '48-72' => 1, '72-96' => 1, '96-144' => 0, '144-' => 0])
        ->and(facetCounts($response, 'price_band'))->toBe(['0-500' => 1, '500-1000' => 0, '1000-2500' => 1, '2500-5000' => 0, '5000-' => 0])
        ->and(facetCounts($response, 'gender'))->toBe(['unisex' => 1, 'boys' => 1, 'girls' => 0])
        ->and(facetCounts($response, 'rating'))->toBe(['4' => 1, '3' => 1, '2' => 1, '1' => 1])
        ->and($response->json('meta.facets.educational'))->toBe(['count' => 1, 'selected' => false]);

    // Each count is exactly the total you get by applying that option.
    $checks = [
        ['brand', 'hape', ['filter[brand]' => 'hape']],
        ['category', 'puzzles', ['filter[category]' => 'puzzles']],
        ['age_band', '24-48', ['filter[age_band]' => '24-48']],
        ['price_band', '0-500', ['filter[price_band]' => '0-500']],
        ['gender', 'unisex', ['filter[gender]' => 'unisex']],
        ['rating', '3', ['filter[rating]' => '3']],
    ];
    foreach ($checks as [$facet, $value, $override]) {
        $applied = listing([...$base, ...$override])->assertOk();
        expect($applied->json('meta.total'))->toBe(facetCounts($response, $facet)[$value], "{$facet}={$value}")
            ->and(count($applied->json('data')))->toBe($applied->json('meta.total'));
    }
});

it('keeps a selected brand with no matches in the brand facet', function (): void {
    Brand::factory()->create(['slug' => 'lego', 'name' => 'LEGO']);
    listed(['slug' => 'a', 'age_min_months' => 0, 'age_max_months' => 12]);

    $response = listing(['filter[brand]' => 'lego'])->assertOk();

    expect($response->json('meta.facets.brand'))->toBe([
        ['value' => 'lego', 'label' => 'LEGO', 'label_bn' => $response->json('meta.facets.brand.0.label_bn'), 'count' => 0, 'selected' => true],
    ]);
});

it('lists root categories as the category facet without a category filter', function (): void {
    $toys = Category::factory()->create(['slug' => 'toys']);
    Category::factory()->create(['slug' => 'books']);
    listed(['primary_category_id' => Category::factory()->childOf($toys)->create()->id]);

    expect(facetCounts(listing(), 'category'))->toBe(['toys' => 1, 'books' => 0]);
});

it('rejects an unlisted filter, sort or parameter with a 400 problem', function (array $query): void {
    listing($query)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    'unlisted filter' => [['filter[stock]' => 'in_stock']],
    'bad availability' => [['filter[availability]' => 'pre_order']],
    'unlisted sort' => [['sort' => 'popular']],
    'spatie-style sort' => [['sort' => '-price']],
    'unknown parameter' => [['page' => '2']],
    'filter not an object' => [['filter' => 'lego']],
    'bad age' => [['filter[age]' => 'two']],
    'age too high' => [['filter[age]' => '217']],
    'bad age band' => [['filter[age_band]' => '0-6']],
    'bad price' => [['filter[price_min]' => '10.999']],
    'negative price' => [['filter[price_min]' => '-5']],
    'min above max' => [['filter[price_min]' => '500', 'filter[price_max]' => '100']],
    'bad price band' => [['filter[price_band]' => '0-100']],
    'bad gender' => [['filter[gender]' => 'kids']],
    'bad rating' => [['filter[rating]' => '6']],
    'bad educational' => [['filter[educational]' => 'yes']],
    'empty availability' => [['filter[availability]' => '']],
    'bad brand slug' => [['filter[brand]' => 'LEGO!']],
    'bad category slug' => [['filter[category]' => 'Toys/x']],
    'empty filter' => [['filter[brand]' => '']],
    'array filter' => [['filter[brand][]' => 'lego']],
]);

it('loads a full page in a constant number of queries', function (): void {
    $brand = Brand::factory()->create();
    $category = Category::factory()->create(['slug' => 'toys']);
    $makePage = function (int $count) use ($brand, $category): void {
        foreach (range(1, $count) as $i) {
            $p = listed(['brand_id' => $brand->id, 'primary_category_id' => $category->id]);
            ProductImage::factory()->for($p)->create();
            ProductVariant::factory()->for($p)->create(['price' => '99.00']);
        }
    };

    $token = listingToken();
    $count = function () use ($token): int {
        listing(['filter[category]' => 'toys'], $token)->assertOk(); // warm the token lookup
        auth()->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        listing(['filter[category]' => 'toys'], $token)->assertOk();
        auth()->forgetGuards();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $makePage(2);
    $few = $count();
    $makePage(22);
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(20);
});

it('requires a bearer token with storefront:read', function (): void {
    $this->getJson('/api/v1/storefront/products')
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');

    auth()->forgetGuards();
    $this->withToken(listingToken(['orders.view']))->getJson('/api/v1/storefront/products')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden');
});

it('refuses a staff member\'s token that carries admin abilities only', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(App\Models\Enums\Role::SuperAdmin)->create();

    $this->withToken($admin->createToken('admin', ['catalog.view'])->plainTextToken)
        ->getJson('/api/v1/storefront/products')
        ->assertForbidden();
});

it('filters to in-stock products and counts them in the availability facet (P4-CAT-13)', function (): void {
    $stocked = listed(['slug' => 'stocked', 'name' => 'Stocked']);
    $soldOut = listed(['slug' => 'sold-out', 'name' => 'Sold out']);
    $reserved = listed(['slug' => 'all-reserved', 'name' => 'All reserved']);
    $archivedStock = listed(['slug' => 'archived-stock', 'name' => 'Archived stock']);
    listed(['slug' => 'never-stocked', 'name' => 'Never stocked']);
    $archivedVariant = ProductVariant::factory()->for($archivedStock)->archived()->create();

    $locationId = (int) DB::table('stock_locations')->value('id');
    $level = fn (Product|ProductVariant $of, int $onHand, int $reserved = 0) => DB::table('stock_levels')->insert([
        'variant_id' => $of instanceof Product ? $of->variants()->value('id') : $of->id,
        'location_id' => $locationId, 'on_hand' => $onHand, 'reserved' => $reserved, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $level($stocked, 3);
    $level($soldOut, 0);
    $level($reserved, 2, 2);
    $level($archivedVariant, 9);

    $all = listing(['sort' => 'name'])->assertOk();
    expect(collect($all->json('data'))->pluck('in_stock', 'slug')->all())->toBe([
        'all-reserved' => false, 'archived-stock' => false, 'never-stocked' => false, 'sold-out' => false, 'stocked' => true,
    ])
        ->and($all->json('meta.facets.availability'))->toBe(['count' => 1, 'selected' => false]);

    $only = listing(['filter[availability]' => 'in_stock'])->assertOk();
    expect(slugs($only))->toBe(['stocked'])
        ->and($only->json('meta.total'))->toBe(1)
        // Disjunctive: the facet ignores its own filter.
        ->and($only->json('meta.facets.availability'))->toBe(['count' => 1, 'selected' => true]);
});
