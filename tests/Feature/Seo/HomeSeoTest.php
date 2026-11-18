<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Seo\HomeSeoSubjects;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\SeoMeta;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The homepage as an SEO subject (`home`, id 1, slug `home`): the Pages list's Home row opens the
 * same SEO panel as a page. Its defaults are Site & branding — the storefront's head before the
 * document existed — so an untouched document changes nothing.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

function homeBrand(string $field, string $value): void
{
    Setting::put(StoreBranding::PREFIX.$field, $value, SettingGroup::Store, false, null);
}

it('defaults the home title and description to Site & branding, with the catalog copy as fallback', function (): void {
    $this->getJson('/api/v1/admin/seo/meta/home/1')->assertOk()
        ->assertJsonPath('defaults.title', 'Toy Mart BD | Toys for every age, delivered across Bangladesh.')
        ->assertJsonPath('defaults.title_bn', 'টয় মার্ট বিডি | সব বয়সের খেলনা, সারা বাংলাদেশে ডেলিভারি।')
        ->assertJsonPath('defaults.description', 'Toys for 0–12 years with the age, price and stock stated on every one. Cash on delivery across Bangladesh.');

    homeBrand('site_name_en', 'ToyMart');
    homeBrand('tagline_en', 'Play more');
    homeBrand('description_en', 'The toy shop.');
    $this->getJson('/api/v1/admin/seo/meta/home/1')->assertOk()
        ->assertJsonPath('defaults.title', 'ToyMart | Play more')
        ->assertJsonPath('defaults.description', 'The toy shop.');
});

it('saves the home SEO document and purges the homepage head', function (): void {
    Event::fake([SeoPagesStale::class]);
    $doc = $this->getJson('/api/v1/admin/seo/meta/home/1')->assertOk()->json();

    $this->putJson('/api/v1/admin/seo/meta/home/1', [
        'title' => 'Toy Mart BD — toys in Dhaka',
        'title_bn' => 'টয় মার্ট বিডি — ঢাকায় খেলনা',
        'description' => 'Buy toys online.',
        'description_bn' => null,
        'canonical_url' => null,
        'robots' => 'index,follow',
        'sitemap_exclude' => false,
        'og_title' => null,
        'og_description' => null,
        'og_title_bn' => null,
        'og_description_bn' => null,
        'og_image' => null,
        'twitter_card' => 'summary_large_image',
        'focus_keyword' => 'toys',
    ])->assertOk()->assertJsonPath('title', 'Toy Mart BD — toys in Dhaka');

    expect(SeoMeta::for('home', 1)->first()?->description)->toBe('Buy toys online.');
    Event::assertDispatched(SeoPagesStale::class, fn (SeoPagesStale $e): bool => in_array('home:home', $e->tags, true));
    expect($doc['defaults']['title'])->toContain('Toy Mart BD');
});

it('serves the effective home document to the storefront', function (): void {
    SeoMeta::query()->create(['seoable_type' => 'home', 'seoable_id' => 1, 'description' => 'Own description', 'robots' => 'index,follow', 'twitter_card' => 'summary_large_image', 'sitemap_exclude' => false]);
    auth()->forgetGuards();
    $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/storefront/seo/home/home')->assertOk()
        ->assertJsonPath('en.title', 'Toy Mart BD | Toys for every age, delivered across Bangladesh.')
        ->assertJsonPath('en.description', 'Own description');
});

it('has only one home: other ids and slugs are 404', function (): void {
    $this->getJson('/api/v1/admin/seo/meta/home/2')->assertNotFound();
});

it('refuses the home SEO panel to a role without SEO access', function (): void {
    $this->actingAs(User::factory()->withRole(Role::OrderOperator)->create()->refresh(), 'web');
    $this->getJson('/api/v1/admin/seo/meta/home/1')->assertForbidden();
});

it('purges the home SEO document when Site & branding changes, since branding is its default', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $this->putJson('/api/v1/admin/settings/branding', ['tagline_en' => 'New tagline'])->assertOk();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => in_array(HomeSeoSubjects::TYPE.':'.HomeSeoSubjects::SLUG, $job->tags, true));
});

it('scores any SEO document with server checks: both results fit, keyword, share image, indexable', function (): void {
    $checks = fn (): array => collect($this->getJson('/api/v1/admin/seo/meta/home/1')->assertOk()->json('checklist.items'))->pluck('status', 'key')->all();

    expect($checks())->toMatchArray(['title_fits' => 'pass', 'keyword_in_title' => 'warn', 'social_image' => 'warn', 'indexable' => 'pass']);

    SeoMeta::query()->create(['seoable_type' => 'home', 'seoable_id' => 1, 'focus_keyword' => 'toys', 'og_image' => 'https://cdn.example.com/share.jpg', 'robots' => 'noindex,follow', 'twitter_card' => 'summary_large_image', 'sitemap_exclude' => false]);
    $doc = $this->getJson('/api/v1/admin/seo/meta/home/1')->assertOk();
    expect($checks())->toMatchArray(['keyword_in_description' => 'pass', 'social_image' => 'pass', 'indexable' => 'warn'])
        ->and($doc->json('checklist.total'))->toBe(8)
        ->and($doc->json('checklist.passed'))->toBe(collect($doc->json('checklist.items'))->where('status', 'pass')->count());
});

it('takes a Media Library image over http outside production, https only in production', function (): void {
    $body = ['og_image' => 'http://localhost:8000/storage/media/share.jpg', 'robots' => 'index,follow', 'twitter_card' => 'summary_large_image'];
    $this->putJson('/api/v1/admin/seo/meta/home/1', $body)->assertOk()->assertJsonPath('og_image', 'http://localhost:8000/storage/media/share.jpg');

    app()->detectEnvironment(fn (): string => 'production');
    $this->putJson('/api/v1/admin/seo/meta/home/1', $body)->assertUnprocessable();
});
