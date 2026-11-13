<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Seo\Actions\RecordSlugChange;
use App\Domain\Seo\Jobs\GenerateSitemaps;
use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\RedirectMap;
use App\Domain\Seo\Services\RobotsTxt;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Inventory\InventoryOpsKit;
use Tests\Support\RoleMatrix;

/*
 * Phase 7 SEO module (docs/api/seo.md): the per-entity SEO document, slug history → 301,
 * the redirect manager's conflict rules, sitemaps and robots.txt.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('local');
    RedirectMap::forget();

    $this->seed(RolesAndPermissionsSeeder::class);
});

function seoAdmin(Role $role = Role::ContentEditor): User
{
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->flushHeaders();
    auth()->forgetGuards();
    test()->actingAs($user, 'web');

    return $user;
}

function seoStorefront(string $method, string $uri): Illuminate\Testing\TestResponse
{
    $token = User::factory()->create()->createToken('storefront', ['storefront:read'])->plainTextToken;
    auth()->forgetGuards();

    return test()->withToken($token)->json($method, $uri);
}

/** @return list<array{id: int|null, from: string, to: string, status: int}> */
function redirectMap(): array
{
    RedirectMap::forget();

    return app(RedirectMap::class)->entries();
}

// ── Per-entity SEO document ────────────────────────────────────────────────────────

it('serves a category SEO document with server defaults and both-locale SERP previews', function (): void {
    seoAdmin();
    $category = Category::factory()->create(['name' => 'Building Blocks', 'name_bn' => 'বিল্ডিং ব্লক', 'slug' => 'building-blocks', 'description' => null, 'description_bn' => null]);

    $doc = $this->getJson("/api/v1/admin/seo/meta/category/{$category->id}")->assertOk()->json();

    expect($doc['title'])->toBeNull()
        ->and($doc['path'])->toBe('/categories/building-blocks')
        ->and($doc['urls'])->toBe(['en' => 'https://toymartbd.com/categories/building-blocks', 'bn' => 'https://toymartbd.com/bn/categories/building-blocks'])
        ->and($doc['defaults']['title'])->toBe('Building Blocks | Toy Mart BD')
        ->and($doc['defaults']['title_bn'])->toBe('বিল্ডিং ব্লক | Toy Mart BD')
        ->and($doc['defaults']['description'])->toContain('Building Blocks')
        ->and($doc['defaults']['description_bn'])->toContain('বিল্ডিং ব্লক')
        ->and($doc['serp']['en']['title_truncated'])->toBeFalse()
        ->and($doc['serp']['en']['url_display'])->toBe('toymartbd.com › categories › building-blocks')
        ->and($doc['serp']['bn']['url_display'])->toBe('toymartbd.com › bn › categories › building-blocks')
        ->and($doc['in_sitemap'])->toBeTrue();
});

it('saves a brand SEO document with separate Bangla OG and purges the page', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);
    config(['services.storefront.revalidate_secret' => 'x']);
    seoAdmin();
    $brand = Brand::factory()->create(['name' => 'LEGO', 'slug' => 'lego']);

    $long = str_repeat('Wonderful wooden educational toys for kids ', 4);
    $doc = $this->putJson("/api/v1/admin/seo/meta/brand/{$brand->id}", [
        'title' => $long,
        'title_bn' => 'লেগো খেলনা | Toy Mart BD',
        'description' => 'Genuine LEGO sets.',
        'og_title' => 'LEGO at Toy Mart BD',
        'og_title_bn' => 'টয় মার্টে লেগো',
        'og_description_bn' => 'আসল লেগো সেট।',
        'robots' => 'noindex,follow',
    ])->assertOk()->json();

    expect($doc['og_title_bn'])->toBe('টয় মার্টে লেগো')
        ->and($doc['robots'])->toBe('noindex,follow')
        ->and($doc['in_sitemap'])->toBeFalse()
        ->and($doc['serp']['en']['title_truncated'])->toBeTrue()
        ->and($doc['serp']['en']['title_display'])->toEndWith(' …')
        ->and(mb_strlen($doc['serp']['en']['title_display']))->toBeLessThan(mb_strlen($long));

    Bus::assertDispatched(RevalidateStorefrontTags::class, static fn (RevalidateStorefrontTags $job): bool => in_array('brand:lego', $job->tags, true) && in_array('seo', $job->tags, true));

    // The storefront reads effective values per locale.
    $sf = seoStorefront('GET', '/api/v1/storefront/seo/brand/lego')->assertOk()->json();
    expect($sf['en']['title'])->toBe(trim($long))
        ->and($sf['bn']['title'])->toBe('লেগো খেলনা | Toy Mart BD')
        ->and($sf['bn']['og_title'])->toBe('টয় মার্টে লেগো')
        ->and($sf['bn']['og_description'])->toBe('আসল লেগো সেট।')
        ->and($sf['en']['og_description'])->toBe('Genuine LEGO sets.')
        ->and($sf['robots'])->toBe(['index' => false, 'follow' => true]);
});

it('measures unsaved text for the SERP preview on the server', function (): void {
    seoAdmin(Role::Viewer);

    $preview = $this->postJson('/api/v1/admin/seo/serp-preview', ['title' => str_repeat('W', 80), 'description' => 'Short.', 'url' => '/bn/products/x'])->assertOk()->json();

    expect($preview['title_truncated'])->toBeTrue()
        ->and($preview['title_px'])->toBeGreaterThan($preview['title_max'])
        ->and($preview['description_truncated'])->toBeFalse()
        ->and($preview['url_display'])->toBe('toymartbd.com › bn › products › x');
});

it('404s an unknown type or id, and a storefront SEO read for a page that is not live', function (): void {
    seoAdmin();
    $this->getJson('/api/v1/admin/seo/meta/widget/1')->assertNotFound();
    $this->getJson('/api/v1/admin/seo/meta/category/999999')->assertNotFound();

    Product::factory()->draft()->create(['slug' => 'secret-draft']);
    Brand::factory()->create(['slug' => 'gone', 'status' => BrandStatus::Archived]);
    seoStorefront('GET', '/api/v1/storefront/seo/product/secret-draft')->assertNotFound();
    seoStorefront('GET', '/api/v1/storefront/seo/brand/gone')->assertNotFound();
});

it('needs the storefront token for the storefront SEO reads', function (): void {
    $this->getJson('/api/v1/storefront/redirects')->assertUnauthorized();
    $this->getJson('/api/v1/storefront/robots')->assertUnauthorized();
});

// ── Role matrix (rule 21) ──────────────────────────────────────────────────────────

it('guards reading SEO documents with seo.view', function (): void {
    $category = Category::factory()->create();
    RoleMatrix::assert($this, Permission::SeoView, fn () => $this->getJson("/api/v1/admin/seo/meta/category/{$category->id}"));
});

it('guards saving SEO documents with seo.manage', function (): void {
    $category = Category::factory()->create();
    RoleMatrix::assert($this, Permission::SeoManage, fn () => $this->putJson("/api/v1/admin/seo/meta/category/{$category->id}", ['title' => 'T']));
});

it('guards the redirect manager, sitemap and robots endpoints', function (): void {
    RoleMatrix::assert($this, Permission::SeoView, fn () => $this->getJson('/api/v1/admin/seo/redirects'));
    RoleMatrix::assert($this, Permission::SeoView, fn () => $this->getJson('/api/v1/admin/seo/slug-history'));
    RoleMatrix::assert($this, Permission::SeoView, fn () => $this->getJson('/api/v1/admin/seo/sitemap'));
    RoleMatrix::assert($this, Permission::SeoView, fn () => $this->getJson('/api/v1/admin/seo/robots'));
    $n = 0;
    RoleMatrix::assert($this, Permission::SeoManage, function () use (&$n) {
        $n++;

        return $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/old-'.$n, 'to_path' => '/toys']);
    }, 201);
    RoleMatrix::assert($this, Permission::SeoManage, fn () => $this->putJson('/api/v1/admin/seo/robots', ['content' => "User-agent: *\nDisallow: /cart\n"]));
    Bus::fake([GenerateSitemaps::class]);
    RoleMatrix::assert($this, Permission::SeoManage, fn () => $this->postJson('/api/v1/admin/seo/sitemap/regenerate'), 202);
    $redirect = Redirect::query()->firstOrFail();
    RoleMatrix::assert($this, Permission::SeoManage, fn () => $this->patchJson("/api/v1/admin/seo/redirects/{$redirect->id}", ['note' => 'x']));
});

// ── Slug history → 301 ─────────────────────────────────────────────────────────────

it('turns two product renames into one-hop 301s from both old slugs', function (): void {
    $product = Product::factory()->create(['slug' => 'alpha']);
    $record = app(RecordSlugChange::class);

    $product->update(['slug' => 'beta']);
    $record->handle('product', $product->id, 'alpha', 'beta', true);
    $product->update(['slug' => 'gamma']);
    $record->handle('product', $product->id, 'beta', 'gamma', true);

    $map = collect(redirectMap())->keyBy('from');
    expect($map['/products/alpha']['to'])->toBe('/products/gamma')
        ->and($map['/products/beta']['to'])->toBe('/products/gamma')
        ->and($map['/products/alpha']['status'])->toBe(301)
        ->and($map->has('/products/gamma'))->toBeFalse();

    // Served to the storefront as one collapsed map.
    $served = seoStorefront('GET', '/api/v1/storefront/redirects')->assertOk()->json('redirects');
    expect(collect($served)->firstWhere('from', '/products/alpha')['to'])->toBe('/products/gamma');
});

it('records a category slug change from the admin edit and 301s the old URL', function (): void {
    seoAdmin(Role::Manager);
    $category = Category::factory()->create(['slug' => 'blocks']);

    $this->patchJson("/api/v1/admin/categories/{$category->id}", ['slug' => 'building-blocks'])->assertOk();

    expect(SlugHistory::query()->where('sluggable_type', 'category')->value('old_slug'))->toBe('blocks');
    expect(collect(redirectMap())->firstWhere('from', '/categories/blocks')['to'])->toBe('/categories/building-blocks');
});

it('stops redirecting a slug that becomes live again', function (): void {
    $product = Product::factory()->create(['slug' => 'one']);
    $record = app(RecordSlugChange::class);
    $product->update(['slug' => 'two']);
    $record->handle('product', $product->id, 'one', 'two', true);
    $product->update(['slug' => 'one']);
    $record->handle('product', $product->id, 'two', 'one', true);

    $map = collect(redirectMap())->keyBy('from');
    expect($map->has('/products/one'))->toBeFalse()
        ->and($map['/products/two']['to'])->toBe('/products/one');
});

it('never records a redirect from a draft URL', function (): void {
    $product = Product::factory()->draft()->create(['slug' => 'draft-a']);
    $product->update(['slug' => 'draft-b']);
    app(RecordSlugChange::class)->handle('product', $product->id, 'draft-a', 'draft-b', false);

    expect(SlugHistory::query()->count())->toBe(0);
});

// ── Redirect manager rules ─────────────────────────────────────────────────────────

it('stores a redirect locale-independently and serves it', function (): void {
    seoAdmin();

    $r = $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/bn/old-offer/', 'to_path' => '/toys?age=24-48', 'status_code' => 302])->assertCreated()->json();

    expect($r['from_path'])->toBe('/old-offer')->and($r['to_path'])->toBe('/toys?age=24-48')->and($r['status_code'])->toBe(302)->and($r['source'])->toBe('manual');
    expect(collect(redirectMap())->firstWhere('from', '/old-offer'))->toMatchArray(['to' => '/toys?age=24-48', 'status' => 302, 'id' => $r['id']]);
});

it('refuses a source that is a live page or a built-in route', function (): void {
    seoAdmin();
    Product::factory()->create(['slug' => 'live-one']);

    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/products/live-one', 'to_path' => '/toys'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'from_path');
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/checkout', 'to_path' => '/toys'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/account/orders', 'to_path' => '/toys'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => 'no-slash', 'to_path' => '/toys'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/x', 'to_path' => 'http://evil.example'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'to_path');
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/x', 'to_path' => '/bn/x'])->assertUnprocessable();
});

it('refuses a duplicate source and a loop', function (): void {
    seoAdmin();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/a', 'to_path' => '/b'])->assertCreated();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/a', 'to_path' => '/c'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/b', 'to_path' => '/a'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'to_path');

    // A loop through a slug-history redirect is caught too.
    $product = Product::factory()->create(['slug' => 'p-new']);
    app(RecordSlugChange::class)->handle('product', $product->id, 'p-old', 'p-new', true);
    $product->update(['status' => 'archived']);
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/products/p-new', 'to_path' => '/products/p-old'])->assertUnprocessable();
});

it('collapses chains in both directions', function (): void {
    seoAdmin();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/b', 'to_path' => '/c'])->assertCreated();
    // A → B where B → C is stored A → C.
    $ab = $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/a', 'to_path' => '/b'])->assertCreated()->json();
    expect($ab['to_path'])->toBe('/c');

    // C → D re-points A and B straight to D.
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/c', 'to_path' => '/d'])->assertCreated();
    expect(Redirect::query()->pluck('to_path', 'from_path')->all())->toBe(['/b' => '/d', '/a' => '/d', '/c' => '/d']);

    $map = collect(redirectMap())->pluck('to', 'from')->all();
    expect($map)->toBe(['/a' => '/d', '/b' => '/d', '/c' => '/d']);
});

it('prunes a manual redirect whose source has since become a live page', function (): void {
    seoAdmin();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/products/soon', 'to_path' => '/toys'])->assertCreated();
    Product::factory()->create(['slug' => 'soon']);

    expect(collect(redirectMap())->pluck('from')->all())->not->toContain('/products/soon');
});

it('updates, deactivates and deletes a redirect', function (): void {
    seoAdmin();
    $id = $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/old', 'to_path' => '/toys'])->assertCreated()->json('id');

    $this->patchJson("/api/v1/admin/seo/redirects/{$id}", ['is_active' => false, 'note' => 'Spring sale over'])->assertOk()->assertJsonPath('is_active', false);
    expect(redirectMap())->toBe([]);

    $this->getJson('/api/v1/admin/seo/redirects?filter[is_active]=0')->assertOk()->assertJsonPath('data.0.note', 'Spring sale over');
    $this->getJson('/api/v1/admin/seo/redirects?filter[nope]=1')->assertStatus(400);

    $this->deleteJson("/api/v1/admin/seo/redirects/{$id}")->assertNoContent();
    expect(Redirect::query()->count())->toBe(0);
});

it('imports a CSV all or nothing', function (): void {
    seoAdmin();
    $good = UploadedFile::fake()->createWithContent('r.csv', "from_path,to_path,status_code,note\n/x1,/toys,301,first\n/bn/x2,/brands,302,\n");
    $this->post('/api/v1/admin/seo/redirects/import', ['file' => $good], ['Accept' => 'application/json'])->assertOk()->assertJson(['created' => 2, 'updated' => 0, 'errors' => []]);
    expect(Redirect::query()->where('source', 'import')->count())->toBe(2);

    $bad = UploadedFile::fake()->createWithContent('r.csv', "from_path,to_path,status_code\n/x3,/toys,301\n/cart,/toys,301\n/x1,/categories,301\n");
    $res = $this->post('/api/v1/admin/seo/redirects/import', ['file' => $bad], ['Accept' => 'application/json'])->assertUnprocessable()->json();
    expect($res['errors'][0])->toMatchArray(['line' => 3, 'field' => 'from_path']);
    expect(Redirect::query()->where('from_path', '/x3')->exists())->toBeFalse()
        ->and(Redirect::query()->where('from_path', '/x1')->value('to_path'))->toBe('/toys');

    $noHeader = UploadedFile::fake()->createWithContent('r.csv', "/a,/b,301\n");
    $this->post('/api/v1/admin/seo/redirects/import', ['file' => $noHeader], ['Accept' => 'application/json'])->assertUnprocessable();
});

it('counts storefront hits on a manual redirect', function (): void {
    seoAdmin();
    $id = $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/hit-me', 'to_path' => '/toys'])->json('id');

    seoStorefront('POST', "/api/v1/storefront/redirects/{$id}/hit")->assertNoContent();
    seoStorefront('POST', "/api/v1/storefront/redirects/{$id}/hit")->assertNoContent();

    $row = Redirect::query()->findOrFail($id);
    expect($row->hit_count)->toBe(2)->and($row->last_hit_at)->not->toBeNull();
});

it('lists automatic slug redirects with their paths', function (): void {
    seoAdmin(Role::Viewer);
    $product = Product::factory()->create(['slug' => 'new-name', 'name' => 'Robot Kit']);
    app(RecordSlugChange::class)->handle('product', $product->id, 'old-name', 'new-name', true);

    $this->getJson('/api/v1/admin/seo/slug-history?filter[type]=product')->assertOk()
        ->assertJsonPath('data.0.from_path', '/products/old-name')
        ->assertJsonPath('data.0.to_path', '/products/new-name')
        ->assertJsonPath('data.0.entity_name', 'Robot Kit');
});

// ── Sitemaps ───────────────────────────────────────────────────────────────────────

it('builds locale-partitioned sitemaps of live, indexable pages only', function (): void {
    $live = Product::factory()->create(['slug' => 'live-toy']);
    Product::factory()->draft()->create(['slug' => 'draft-toy']);
    Product::factory()->archived()->create(['slug' => 'archived-toy']);
    Product::factory()->create(['slug' => 'pos-only', 'channel_online_store' => false]);
    $noindex = Product::factory()->create(['slug' => 'hidden-toy']);
    SeoMeta::query()->create(['seoable_type' => 'product', 'seoable_id' => $noindex->id, 'robots' => 'noindex,follow']);
    $excluded = Product::factory()->create(['slug' => 'excluded-toy']);
    SeoMeta::query()->create(['seoable_type' => 'product', 'seoable_id' => $excluded->id, 'sitemap_exclude' => true]);
    Brand::factory()->create(['slug' => 'lego']);
    Brand::factory()->create(['slug' => 'old-brand', 'status' => BrandStatus::Archived]);

    $manifest = app(SitemapBuilder::class)->build();
    $files = collect($manifest['files'])->keyBy('file');

    expect($files->keys()->all())->toContain('sitemap-products-en-1.xml', 'sitemap-products-bn-1.xml', 'sitemap-brands-en-1.xml', 'sitemap-brands-bn-1.xml', 'sitemap-categories-en-1.xml', 'sitemap-static-en-1.xml')
        ->and($files['sitemap-brands-en-1.xml']['urls'])->toBe(1);

    $en = (string) SitemapBuilder::read('sitemap-products-en-1.xml');
    $bn = (string) SitemapBuilder::read('sitemap-products-bn-1.xml');
    expect($en)->toContain('<loc>https://toymartbd.com/products/live-toy</loc>')
        ->and($en)->toContain('hreflang="bn" href="https://toymartbd.com/bn/products/live-toy"')
        ->and($en)->toContain('hreflang="x-default" href="https://toymartbd.com/products/live-toy"')
        ->and($en)->toContain('<lastmod>')
        ->and($en)->not->toContain('draft-toy')->not->toContain('archived-toy')->not->toContain('pos-only')->not->toContain('hidden-toy')->not->toContain('excluded-toy')
        ->and($bn)->toContain('<loc>https://toymartbd.com/bn/products/live-toy</loc>')
        ->and($bn)->not->toContain('<loc>https://toymartbd.com/products/');

    // Well-formed XML, and the index lists every file at its public URL.
    expect(simplexml_load_string($en))->not->toBeFalse();
    $index = (string) SitemapBuilder::read('sitemap.xml');
    expect(simplexml_load_string($index))->not->toBeFalse();
    foreach ($files->keys() as $file) {
        expect($index)->toContain('<loc>https://toymartbd.com/sitemaps/'.$file.'</loc>');
    }

    expect(app(SitemapBuilder::class)->build()['total_urls'])->toBe($manifest['total_urls']);
    expect($live->id)->toBeInt();
});

it('chunks a sitemap partition and removes stale files', function (): void {
    Product::factory()->count(5)->create();
    $builder = app(SitemapBuilder::class);
    $builder->chunk = 2;

    $files = collect($builder->build()['files'])->where('partition', 'products');
    expect($files->where('locale', 'en')->pluck('urls')->all())->toBe([2, 2, 1])
        ->and($files->where('locale', 'bn')->count())->toBe(3);

    $builder->chunk = 50000;
    $builder->build();
    expect(SitemapBuilder::read('sitemap-products-en-3.xml'))->toBeNull()
        ->and(SitemapBuilder::read('sitemap-products-en-1.xml'))->not->toBeNull();
});

it('serves sitemaps to the storefront and reports the build to the admin', function (): void {
    Product::factory()->create(['slug' => 'served-toy']);
    (new GenerateSitemaps)->handle(app(SitemapBuilder::class));

    $res = seoStorefront('GET', '/api/v1/storefront/sitemaps/sitemap-products-bn-1.xml')->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/xml')
        ->and($res->getContent())->toContain('/bn/products/served-toy');
    seoStorefront('GET', '/api/v1/storefront/sitemaps/sitemap.xml')->assertOk();
    seoStorefront('GET', '/api/v1/storefront/sitemaps/sitemap-products-de-1.xml')->assertNotFound();

    seoAdmin(Role::Viewer);
    $status = $this->getJson('/api/v1/admin/seo/sitemap')->assertOk()->json();
    expect($status['total_urls'])->toBeGreaterThan(0)
        ->and($status['sitemap_url'])->toBe('https://toymartbd.com/sitemap.xml')
        ->and(collect($status['files'])->firstWhere('file', 'sitemap-products-en-1.xml')['url'])->toBe('https://toymartbd.com/sitemaps/sitemap-products-en-1.xml');
});

it('queues a rebuild on demand', function (): void {
    Bus::fake([GenerateSitemaps::class]);
    seoAdmin();
    $this->postJson('/api/v1/admin/seo/sitemap/regenerate')->assertStatus(202);
    Bus::assertDispatched(GenerateSitemaps::class);
});

// ── robots.txt ─────────────────────────────────────────────────────────────────────

it('serves the default robots.txt with the sitemap line', function (): void {
    $res = seoStorefront('GET', '/api/v1/storefront/robots')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('text/plain')
        ->and($res->getContent())->toContain("User-agent: *\n")
        ->and($res->getContent())->toContain('Disallow: /checkout')
        ->and($res->getContent())->toContain('Sitemap: https://toymartbd.com/sitemap.xml');
});

it('validates and saves an edited robots.txt, always adding the sitemap line', function (): void {
    seoAdmin();

    $this->putJson('/api/v1/admin/seo/robots', ['content' => "Disallow: /x\n"])->assertUnprocessable();
    $this->putJson('/api/v1/admin/seo/robots', ['content' => "User-agent: *\nNoindex: /x\n"])->assertUnprocessable();
    $this->putJson('/api/v1/admin/seo/robots', ['content' => "User-agent: *\nDisallow: /\n"])->assertUnprocessable();
    $this->putJson('/api/v1/admin/seo/robots', ['content' => "User-agent: *\nDisallow: nope\n"])->assertUnprocessable();
    $this->putJson('/api/v1/admin/seo/robots', ['content' => "User-agent: *\nSitemap: not-a-url\n"])->assertUnprocessable();

    $saved = $this->putJson('/api/v1/admin/seo/robots', ['content' => "# Toy Mart\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /cart\nCrawl-delay: 2\n"])->assertOk()->json();
    expect($saved['is_default'])->toBeFalse()
        ->and($saved['served'])->toContain("User-agent: GPTBot\nDisallow: /")
        ->and($saved['served'])->toEndWith("Sitemap: https://toymartbd.com/sitemap.xml\n");

    expect(seoStorefront('GET', '/api/v1/storefront/robots')->getContent())->toBe(RobotsTxt::served());
});

it('keeps both correctness invariants (SEO writes touch no stock or ledger row)', function (): void {
    seoAdmin();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/inv', 'to_path' => '/toys'])->assertCreated();
    app(SitemapBuilder::class)->build();

    InventoryOpsKit::assertInvariants();
});

// ── Content types (CMS pages, blog articles, buying guides) ─────────────────────────

it('covers CMS pages, articles and guides in the SEO document, sitemaps and conflict checks', function (): void {
    $page = App\Domain\Content\Models\CmsPage::query()->create(['slug' => 'returns', 'title' => 'Returns', 'title_bn' => 'রিটার্ন', 'content' => '<p>Return any toy within 7 days.</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
    App\Domain\Content\Models\CmsPage::query()->create(['slug' => 'draft-page', 'title' => 'Draft', 'status' => 'draft']);
    $article = Tests\Feature\Content\Blog\BlogKit::post(['slug' => 'why-blocks']);
    Tests\Feature\Content\Blog\BlogKit::post(['slug' => 'best-toys-for-3', 'type' => App\Domain\Content\Blog\Enums\PostType::BuyingGuide]);

    $sf = seoStorefront('GET', '/api/v1/storefront/seo/page/returns')->assertOk()->json();
    expect($sf['path'])->toBe('/pages/returns')
        ->and($sf['en']['title'])->toBe('Returns | Toy Mart BD')
        ->and($sf['bn']['title'])->toBe('রিটার্ন | Toy Mart BD')
        ->and($sf['en']['description'])->toBe('Return any toy within 7 days.');
    expect(seoStorefront('GET', '/api/v1/storefront/seo/blog_post/best-toys-for-3')->assertOk()->json('path'))->toBe('/guides/best-toys-for-3');
    seoStorefront('GET', '/api/v1/storefront/seo/page/draft-page')->assertNotFound();

    $files = collect(app(SitemapBuilder::class)->build()['files'])->pluck('file')->all();
    expect($files)->toContain('sitemap-pages-en-1.xml', 'sitemap-blog-bn-1.xml', 'sitemap-guides-en-1.xml');
    expect((string) SitemapBuilder::read('sitemap-guides-bn-1.xml'))->toContain('<loc>https://toymartbd.com/bn/guides/best-toys-for-3</loc>');
    expect((string) SitemapBuilder::read('sitemap-pages-en-1.xml'))->not->toContain('draft-page');
    expect((string) SitemapBuilder::read('sitemap-static-en-1.xml'))->toContain('<loc>https://toymartbd.com/guides</loc>');

    seoAdmin();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/guides/best-toys-for-3', 'to_path' => '/toys'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/seo/redirects', ['from_path' => '/blog/best-toys-for-3', 'to_path' => '/toys'])->assertCreated();
    $this->getJson("/api/v1/admin/seo/meta/page/{$page->id}")->assertOk()->assertJsonPath('urls.bn', 'https://toymartbd.com/bn/pages/returns');
    $this->getJson("/api/v1/admin/seo/meta/blog_post/{$article->id}")->assertOk()->assertJsonPath('path', '/blog/why-blocks');
});
