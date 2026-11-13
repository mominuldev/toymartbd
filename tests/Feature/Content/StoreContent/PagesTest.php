<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Static pages (Phase 7): admin CRUD, publish/schedule, sanitising, SEO, slug history, the
 * storefront read in both locales, and cache revalidation tags.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

function scPageToken(): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
}

/** @param  array<string, mixed>  $overrides */
function scPagePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Delivery policy',
        'title_bn' => 'ডেলিভারি নীতি',
        'content' => '<h2>Dhaka</h2><p>Delivered in 24 hours.</p><script>alert(1)</script>',
        'content_bn' => '<p>ঢাকায় ২৪ ঘণ্টায় ডেলিভারি।</p>',
        'template' => 'default',
        'status' => 'published',
    ], $overrides);
}

it('creates and publishes a page with a generated ASCII slug and sanitised HTML', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload())
        ->assertCreated()
        ->assertJsonPath('slug', 'delivery-policy')
        ->assertJsonPath('state', 'live')
        ->assertJsonPath('title_bn', 'ডেলিভারি নীতি')
        ->assertJsonPath('content', '<h2>Dhaka</h2><p>Delivered in 24 hours.</p>')
        ->assertJsonPath('path', '/pages/delivery-policy');
});

it('transliterates a Bangla-only title into the shared slug', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['title' => 'আমাদের কথা', 'title_bn' => null]))
        ->assertCreated()
        ->assertJsonPath('slug', fn (string $slug): bool => preg_match('/^[a-z0-9-]+$/', $slug) === 1);
});

it('schedules a page: hidden on the storefront until its Dhaka publish time', function (): void {
    CarbonImmutable::setTestNow('2026-12-01T00:00:00Z');

    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['published_at' => '2026-12-01T09:00:00+06:00']))
        ->assertCreated()
        ->assertJsonPath('state', 'scheduled')
        ->assertJsonPath('published_at', '2026-12-01T03:00:00Z');

    $token = scPageToken();
    $this->withToken($token)->getJson('/api/v1/storefront/pages/delivery-policy')->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2026-12-01T03:00:00Z'));
    $this->withToken($token)->getJson('/api/v1/storefront/pages/delivery-policy')->assertOk();
});

it('keeps a draft off the storefront and its list', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['status' => 'draft']))->assertCreated()->assertJsonPath('state', 'draft');

    $token = scPageToken();
    $this->withToken($token)->getJson('/api/v1/storefront/pages/delivery-policy')->assertNotFound();
    expect($this->withToken($token)->getJson('/api/v1/storefront/pages')->json('data'))->toBe([]);
});

it('serves a live page in both locales with effective SEO', function (): void {
    $id = $this->postJson('/api/v1/admin/content/pages', scPagePayload([
        'seo' => ['title_bn' => 'ডেলিভারি | টয় মার্ট', 'description' => 'How we deliver.'],
    ]))->assertCreated()->json('id');

    expect(SeoMeta::query()->where('seoable_type', 'page')->where('seoable_id', $id)->value('title_bn'))->toBe('ডেলিভারি | টয় মার্ট');

    $this->withToken(scPageToken())->getJson('/api/v1/storefront/pages/delivery-policy')
        ->assertOk()
        ->assertJsonPath('title', 'Delivery policy')
        ->assertJsonPath('title_bn', 'ডেলিভারি নীতি')
        ->assertJsonPath('content_bn', '<p>ঢাকায় ২৪ ঘণ্টায় ডেলিভারি।</p>')
        ->assertJsonPath('seo.title', 'Delivery policy')
        ->assertJsonPath('seo.title_bn', 'ডেলিভারি | টয় মার্ট')
        ->assertJsonPath('seo.description', 'How we deliver.')
        ->assertJsonPath('seo.robots', 'index,follow');
});

it('re-sanitises stored HTML on the storefront read', function (): void {
    CmsPage::query()->create(['slug' => 'raw', 'title' => 'Raw', 'content' => '<p onclick="x()">Hi</p><iframe src="https://evil.test"></iframe>', 'status' => 'published', 'published_at' => now()->subMinute()]);

    $this->withToken(scPageToken())->getJson('/api/v1/storefront/pages/raw')->assertOk()->assertJsonPath('content', '<p>Hi</p>');
});

it('writes a slug_history row when a live page changes slug, only with acknowledgement', function (): void {
    $id = $this->postJson('/api/v1/admin/content/pages', scPagePayload())->json('id');

    $this->putJson("/api/v1/admin/content/pages/{$id}", scPagePayload(['slug' => 'shipping']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'slug');

    $this->putJson("/api/v1/admin/content/pages/{$id}", scPagePayload(['slug' => 'shipping', 'acknowledge_slug_redirect' => true]))
        ->assertOk()->assertJsonPath('slug', 'shipping');

    $this->putJson("/api/v1/admin/content/pages/{$id}", scPagePayload(['slug' => 'delivery', 'acknowledge_slug_redirect' => true]))->assertOk();

    $rows = SlugHistory::query()->where('sluggable_type', 'page')->orderBy('id')->get(['old_slug', 'new_slug', 'sluggable_id'])->toArray();
    expect($rows)->toBe([
        ['old_slug' => 'delivery-policy', 'new_slug' => 'delivery', 'sluggable_id' => $id],
        ['old_slug' => 'shipping', 'new_slug' => 'delivery', 'sluggable_id' => $id],
    ]);
});

it('writes no slug_history for a draft page whose URL was never live', function (): void {
    $id = $this->postJson('/api/v1/admin/content/pages', scPagePayload(['status' => 'draft']))->json('id');

    $this->putJson("/api/v1/admin/content/pages/{$id}", scPagePayload(['status' => 'draft', 'slug' => 'shipping']))->assertOk();

    expect(SlugHistory::query()->where('sluggable_type', 'page')->count())->toBe(0);
});

it('requires an FAQ entry before an FAQ page is published, and sanitises answers', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['title' => 'FAQ', 'template' => 'faq']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'faq_items');

    $this->postJson('/api/v1/admin/content/pages', scPagePayload([
        'title' => 'FAQ', 'template' => 'faq',
        'faq_items' => [['question' => '<b>Do you deliver?</b>', 'question_bn' => 'ডেলিভারি দেন?', 'answer' => '<p>Yes</p><script>x</script>', 'answer_bn' => null]],
    ]))->assertCreated();

    $this->withToken(scPageToken())->getJson('/api/v1/storefront/pages/faq')
        ->assertOk()
        ->assertJsonPath('template', 'faq')
        ->assertJsonPath('faq_items.0.question', 'Do you deliver?')
        ->assertJsonPath('faq_items.0.question_bn', 'ডেলিভারি দেন?')
        ->assertJsonPath('faq_items.0.answer', '<p>Yes</p>');
});

it('archives a page off the storefront', function (): void {
    $id = $this->postJson('/api/v1/admin/content/pages', scPagePayload())->json('id');

    $this->deleteJson("/api/v1/admin/content/pages/{$id}")->assertOk()->assertJsonPath('state', 'archived');
    $this->withToken(scPageToken())->getJson('/api/v1/storefront/pages/delivery-policy')->assertNotFound();
});

it('rejects a non-ASCII or duplicate slug', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['slug' => 'ডেলিভারি']))->assertUnprocessable();
    $this->postJson('/api/v1/admin/content/pages', scPagePayload())->assertCreated();
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['slug' => 'delivery-policy']))->assertUnprocessable();
});

it('filters the admin list by status and refuses an unlisted filter', function (): void {
    $this->postJson('/api/v1/admin/content/pages', scPagePayload())->assertCreated();
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['title' => 'Terms', 'status' => 'draft']))->assertCreated();

    expect($this->getJson('/api/v1/admin/content/pages?filter[status]=draft')->assertOk()->json('data.*.slug'))->toBe(['terms']);
    $this->getJson('/api/v1/admin/content/pages?filter[nope]=1')->assertStatus(400);
});

it('purges the page, old slug and menu tags after a slug change', function (): void {
    $id = $this->postJson('/api/v1/admin/content/pages', scPagePayload())->json('id');
    Queue::fake();

    $this->putJson("/api/v1/admin/content/pages/{$id}", scPagePayload(['slug' => 'shipping', 'acknowledge_slug_redirect' => true]))->assertOk();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:pages', 'content:page:shipping', 'content:page:delivery-policy', 'content:menus']);
});

it('purges a scheduled page when its publish time passes', function (): void {
    CarbonImmutable::setTestNow('2026-12-01T00:00:00Z');
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();
    $this->postJson('/api/v1/admin/content/pages', scPagePayload(['published_at' => '2026-12-01T06:30:00+06:00']))->assertCreated();

    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:31:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => in_array('content:page:delivery-policy', $job->tags, true));
});
