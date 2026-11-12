<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\SerpMeasure;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Search & social on the product form (P4 batch 5, DATABASE-DESIGN §3.9): seo_meta saved
 * with the product document, the server's SERP measurement and checklist, and the
 * slug_history row behind a published product's 301.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seoDocument(Product $product, array $overrides = []): array
{
    return [
        'name' => $product->name,
        'name_bn' => $product->name_bn,
        'slug' => $product->slug,
        'type' => 'simple',
        'status' => $product->status->value,
        'primary_category_id' => $product->primary_category_id,
        'category_ids' => [$product->primary_category_id],
        'variants' => [['id' => $product->variants()->value('id'), 'price' => '1250.00', 'cost_price' => '800.00']],
        ...$overrides,
    ];
}

it('returns server defaults, measurement and checklist when no seo_meta exists', function (): void {
    $product = Product::factory()->create(['name' => 'Wooden Train Set', 'short_description' => 'A pine-wood train with 24 pieces.']);

    $seo = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json('seo');

    expect($seo['title'])->toBeNull()
        ->and($seo['robots'])->toBe('index,follow')
        ->and($seo['defaults']['title'])->toBe('Wooden Train Set | Toy Mart BD')
        ->and($seo['defaults']['description'])->toBe('A pine-wood train with 24 pieces.')
        ->and($seo['url'])->toBe('https://toymartbd.com/products/'.$product->slug)
        ->and($seo['measure']['title']['fits'])->toBeTrue()
        ->and($seo['checklist']['total'])->toBe(7)
        ->and(collect($seo['checklist']['items'])->pluck('status', 'key')->all())->toMatchArray([
            'title_fits' => 'pass',
            'description_fits' => 'pass',
            'primary_image' => 'fail',
            'slug' => 'pass',
            'sitemap' => 'pass',
        ])
        ->and($seo['schema']['@type'])->toBe('Product')
        ->and($seo['schema']['offers']['price'])->toBe('1250.00');
});

it('saves seo_meta with the product document, Bangla included, and keeps it when omitted', function (): void {
    $product = Product::factory()->create();

    $this->putJson("/api/v1/admin/products/{$product->id}", seoDocument($product, ['seo' => [
        'title' => 'Wooden Train Set for Toddlers',
        'title_bn' => 'শিশুদের কাঠের ট্রেন সেট',
        'description' => 'Pine wood, 24 pieces, cash on delivery across Bangladesh.',
        'robots' => 'noindex,follow',
        'focus_keyword' => 'wooden train',
        'schema_override' => ['brand' => ['@type' => 'Brand', 'name' => 'Toy Mart']],
    ]]))
        ->assertOk()
        ->assertJsonPath('seo.title', 'Wooden Train Set for Toddlers')
        ->assertJsonPath('seo.title_bn', 'শিশুদের কাঠের ট্রেন সেট')
        ->assertJsonPath('seo.robots', 'noindex,follow')
        ->assertJsonPath('seo.focus_keyword', 'wooden train')
        ->assertJsonPath('seo.schema.brand.name', 'Toy Mart');

    expect(SeoMeta::forProduct($product->id)->sole()->title_bn)->toBe('শিশুদের কাঠের ট্রেন সেট');

    $this->putJson("/api/v1/admin/products/{$product->id}", seoDocument($product, ['name' => 'Renamed']))
        ->assertOk()
        ->assertJsonPath('seo.title', 'Wooden Train Set for Toddlers')
        ->assertJsonPath('seo.checklist.items.6.params.reason', 'noindex');
});

it('rejects an unknown seo key, a non-https canonical and a bad robots value', function (array $seo, string $field): void {
    $product = Product::factory()->create();

    $response = $this->putJson("/api/v1/admin/products/{$product->id}", seoDocument($product, ['seo' => $seo]))->assertUnprocessable();

    expect(collect($response->json('errors'))->pluck('field')->all())->toContain($field);
})->with([
    'unknown key' => [['keywords' => 'x'], 'seo'],
    'http canonical' => [['canonical_url' => 'http://example.com/x'], 'seo.canonical_url'],
    'robots' => [['robots' => 'all'], 'seo.robots'],
]);

it('measures an over-long title as not fitting', function (): void {
    $this->postJson('/api/v1/admin/products/seo-measure', [
        'title' => str_repeat('Wooden Train Set ', 8),
        'description' => 'Short.',
    ])
        ->assertOk()
        ->assertJsonPath('title.fits', false)
        ->assertJsonPath('title.max', SerpMeasure::TITLE_MAX)
        ->assertJsonPath('description.fits', true)
        ->assertJsonPath('description.chars', 6);
});

it('requires an acknowledgement to change a live product slug, then records a 301', function (): void {
    $product = Product::factory()->create(['slug' => 'wooden-train-set']);

    $this->putJson("/api/v1/admin/products/{$product->id}", seoDocument($product, ['slug' => 'wooden-train-set-24']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'slug');
    expect($product->refresh()->slug)->toBe('wooden-train-set');

    $this->putJson("/api/v1/admin/products/{$product->id}", seoDocument($product, ['slug' => 'wooden-train-set-24', 'acknowledge_slug_redirect' => true]))
        ->assertOk()
        ->assertJsonPath('slug', 'wooden-train-set-24')
        ->assertJsonPath('slug_history.0.old_slug', 'wooden-train-set');

    expect(SlugHistory::query()->sole())->toMatchArray(['old_slug' => 'wooden-train-set', 'new_slug' => 'wooden-train-set-24', 'sluggable_id' => $product->id]);
});

it('records no redirect for a draft slug change and drops one whose old slug goes live again', function (): void {
    $draft = Product::factory()->draft()->create(['slug' => 'draft-toy']);
    $this->putJson("/api/v1/admin/products/{$draft->id}", seoDocument($draft, ['slug' => 'draft-toy-2']))->assertOk();
    expect(SlugHistory::query()->count())->toBe(0);

    $live = Product::factory()->create(['slug' => 'robot-kit']);
    $this->putJson("/api/v1/admin/products/{$live->id}", seoDocument($live, ['slug' => 'robot-kit-v2', 'acknowledge_slug_redirect' => true]))->assertOk();
    $live->refresh();
    $this->putJson("/api/v1/admin/products/{$live->id}", seoDocument($live, ['slug' => 'robot-kit', 'acknowledge_slug_redirect' => true]))->assertOk();

    expect(SlugHistory::query()->pluck('old_slug')->all())->toBe(['robot-kit-v2']);
});

it('fails the slug checklist item when another product retired that slug', function (): void {
    $product = Product::factory()->create(['slug' => 'shared-slug']);
    SlugHistory::query()->create(['sluggable_type' => SeoMeta::TYPE_PRODUCT, 'sluggable_id' => $product->id + 999, 'old_slug' => 'shared-slug', 'new_slug' => 'other']);

    $items = collect($this->getJson("/api/v1/admin/products/{$product->id}")->assertOk()->json('seo.checklist.items'))->pluck('status', 'key');

    expect($items['slug'])->toBe('fail');
});
