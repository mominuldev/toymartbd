<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Seo\Actions\SaveSeoMeta;
use App\Domain\Seo\Contracts\SeoMetaStore;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\RedirectMap;
use App\Domain\Seo\Services\SitemapBuilder;
use App\Domain\Seo\Services\SiteTitle;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Content\Blog\BlogKit;

/*
 * Phase 7 SEO follow-ups: one title separator (config/seo.php) for server and storefront; one
 * write path for seo_meta / slug_history (blog and CMS fold into SaveSeoMeta / RecordSlugChange,
 * behaviour unchanged); blog category / tag / author archives in the SEO panel and sitemaps.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '', 'media.cdn_url' => null]);
    Storage::fake('local');
    RedirectMap::forget();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

function sfToken(): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
}

// ── 1. Title separator ─────────────────────────────────────────────────────────────

it('ends every server default title with the configured separator, " | " by default', function (): void {
    $category = Category::factory()->create(['name' => 'Blocks', 'name_bn' => 'ব্লক']);
    expect(SiteTitle::separator())->toBe(' | ');
    $this->getJson("/api/v1/admin/seo/meta/category/{$category->id}")->assertOk()
        ->assertJsonPath('defaults.title', 'Blocks | Toy Mart BD')
        ->assertJsonPath('defaults.title_bn', 'ব্লক | Toy Mart BD');

    config(['seo.title_separator' => ' · ']);
    $this->getJson("/api/v1/admin/seo/meta/category/{$category->id}")->assertOk()
        ->assertJsonPath('defaults.title', 'Blocks · Toy Mart BD');
    $page = $this->postJson('/api/v1/admin/content/pages', ['title' => 'About', 'content' => '<p>Hi</p>', 'template' => 'default', 'status' => 'published'])->assertCreated()->json('id');
    $this->getJson("/api/v1/admin/content/pages/{$page}")->assertOk()->assertJsonPath('seo.defaults.title', 'About · Toy Mart BD');
});

it('serves the separator to the storefront for its layout title template', function (): void {
    auth()->forgetGuards();
    $this->withToken(sfToken())->getJson('/api/v1/storefront/seo/settings')
        ->assertOk()->assertExactJson(['site_name' => 'Toy Mart BD', 'title_separator' => ' | ']);

    config(['seo.title_separator' => ' · ']);
    $this->withToken(sfToken())->getJson('/api/v1/storefront/seo/settings')->assertOk()->assertJsonPath('title_separator', ' · ');
});

it('needs the storefront token for the title settings', function (): void {
    auth()->forgetGuards();
    $this->getJson('/api/v1/storefront/seo/settings')->assertUnauthorized();
});

// ── 2/3. One write path ────────────────────────────────────────────────────────────

it('binds the SeoMetaStore contract to the generic SaveSeoMeta', function (): void {
    expect(app(SeoMetaStore::class))->toBeInstanceOf(SaveSeoMeta::class);
});

it('merges the blog editor SEO fields without touching what the SEO panel saved (behaviour unchanged)', function (): void {
    $post = BlogKit::post(['slug' => 'merge-me']);
    SeoMeta::query()->create(['seoable_type' => BlogPost::SEO_TYPE, 'seoable_id' => $post->id, 'og_title_bn' => 'প্যানেলের শিরোনাম', 'sitemap_exclude' => true]);

    $this->putJson("/api/v1/admin/blog/posts/{$post->id}", [
        'type' => 'article', 'title' => $post->title, 'body' => '<p>Body</p>', 'status' => 'published',
        'seo' => ['title' => 'Editor title', 'robots' => 'noindex,follow'],
    ])->assertOk();

    $meta = SeoMeta::for(BlogPost::SEO_TYPE, $post->id)->sole();
    expect($meta->title)->toBe('Editor title')
        ->and($meta->robots->value)->toBe('noindex,follow')
        ->and($meta->og_title_bn)->toBe('প্যানেলের শিরোনাম')
        ->and($meta->sitemap_exclude)->toBeTrue();
});

it('drops a deleted draft post’s seo_meta through the contract', function (): void {
    $post = BlogKit::post(['status' => 'draft', 'published_at' => null]);
    SeoMeta::query()->create(['seoable_type' => BlogPost::SEO_TYPE, 'seoable_id' => $post->id, 'title' => 'x']);

    $this->deleteJson("/api/v1/admin/blog/posts/{$post->id}")->assertSuccessful();

    expect(SeoMeta::for(BlogPost::SEO_TYPE, $post->id)->exists())->toBeFalse();
});

it('records a page slug change through RecordSlugChange (redirect map refreshed) and keeps panel-only columns', function (): void {
    Event::fake([SeoPagesStale::class]);
    $id = $this->postJson('/api/v1/admin/content/pages', ['title' => 'Delivery policy', 'content' => '<p>x</p>', 'template' => 'default', 'status' => 'published'])->json('id');
    SeoMeta::query()->create(['seoable_type' => 'page', 'seoable_id' => $id, 'og_title_bn' => 'প্যানেল']);

    $this->putJson("/api/v1/admin/content/pages/{$id}", [
        'title' => 'Delivery policy', 'content' => '<p>x</p>', 'template' => 'default', 'status' => 'published',
        'slug' => 'shipping', 'acknowledge_slug_redirect' => true, 'seo' => ['title' => 'Shipping | Toy Mart BD'],
    ])->assertOk();

    expect(SlugHistory::query()->where('sluggable_type', 'page')->get(['old_slug', 'new_slug'])->toArray())->toBe([['old_slug' => 'delivery-policy', 'new_slug' => 'shipping']])
        ->and(SeoMeta::for('page', $id)->sole()->title)->toBe('Shipping | Toy Mart BD')
        ->and(SeoMeta::for('page', $id)->sole()->og_title_bn)->toBe('প্যানেল');
    Event::assertDispatched(SeoPagesStale::class, static fn (SeoPagesStale $e): bool => $e->tags === ['redirects']);
});

it('keeps Content free of Seo internals (contracts and events only)', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Content')));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && preg_match('/^use App\\\\Domain\\\\Seo\\\\(?!Contracts\\\\|Events\\\\)/m', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

// ── 4. Blog archives in the SEO module ─────────────────────────────────────────────

it('edits a blog category, tag and author archive in the SEO panel and serves it to the storefront', function (): void {
    $category = BlogKit::category('stem', 'STEM');
    $tag = BlogKit::tag('wooden');
    $author = BlogKit::author('rina');
    BlogKit::post(['category_id' => $category->id, 'author_id' => $author->id])->tags()->sync([$tag->id]);

    foreach ([['blog_category', $category->id, 'stem', '/blog/category/stem'], ['blog_tag', $tag->id, 'wooden', '/blog/tag/wooden'], ['blog_author', $author->id, 'rina', '/blog/author/rina']] as [$type, $id, $slug, $path]) {
        auth()->forgetGuards();
        $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
        $this->getJson("/api/v1/admin/seo/meta/{$type}/{$id}")->assertOk()->assertJsonPath('path', $path)->assertJsonPath('in_sitemap', true);
        $this->putJson("/api/v1/admin/seo/meta/{$type}/{$id}", ['title' => "Custom {$slug}", 'title_bn' => "কাস্টম {$slug}", 'robots' => 'index,follow', 'sitemap_exclude' => false, 'twitter_card' => 'summary_large_image'])->assertOk();

        auth()->forgetGuards();
        $this->withToken(sfToken())->getJson("/api/v1/storefront/seo/{$type}/{$slug}")
            ->assertOk()
            ->assertJsonPath('en.title', "Custom {$slug}")
            ->assertJsonPath('bn.title', "কাস্টম {$slug}");
    }
});

it('lists live blog archives in the locale-partitioned sitemaps with hreflang', function (): void {
    $category = BlogKit::category('stem', 'STEM');
    BlogKit::category('empty');
    $tag = BlogKit::tag('wooden');
    $author = BlogKit::author('rina');
    BlogKit::post(['category_id' => $category->id, 'author_id' => $author->id])->tags()->sync([$tag->id]);

    app(SitemapBuilder::class)->build();
    $en = (string) SitemapBuilder::read('sitemap-blog-en-1.xml');
    $bn = (string) SitemapBuilder::read('sitemap-blog-bn-1.xml');

    foreach (['/blog/category/stem', '/blog/tag/wooden', '/blog/author/rina'] as $path) {
        expect($en)->toContain('<loc>https://toymartbd.com'.$path.'</loc>')
            ->and($en)->toContain('hreflang="bn" href="https://toymartbd.com/bn'.$path.'"')
            ->and($en)->toContain('hreflang="x-default" href="https://toymartbd.com'.$path.'"')
            ->and($bn)->toContain('<loc>https://toymartbd.com/bn'.$path.'</loc>');
    }
    expect($en)->not->toContain('/blog/category/empty');
});
