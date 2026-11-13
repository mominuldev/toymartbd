<?php

declare(strict_types=1);

use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Content\Blog\BlogKit;

/*
 * Admin blog posts (Phase 7): /api/v1/admin/blog/posts — sanitisation, reading time, slugs,
 * Dhaka-time scheduling, slug history, seo_meta, revalidation tags.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

/** @param  array<string, mixed>  $overrides  @return array<string, mixed> */
function blogPayload(array $overrides = []): array
{
    return [
        'type' => 'article',
        'title' => 'Best educational toys for 3–5 year olds',
        'title_bn' => '৩–৫ বছরের শিশুদের জন্য সেরা শিক্ষামূলক খেলনা',
        'excerpt' => 'What Dhaka parents told us.',
        'excerpt_bn' => 'ঢাকার অভিভাবকেরা যা বললেন।',
        'body' => '<p>Choosing a toy is hard.</p>',
        'body_bn' => '<p>খেলনা বাছাই করা কঠিন।</p>',
        'status' => 'draft',
        ...$overrides,
    ];
}

it('creates a draft with an ASCII slug, sanitised body and server-computed reading time', function (): void {
    Queue::fake();
    $words = implode(' ', array_fill(0, 450, 'word'));

    $response = $this->postJson('/api/v1/admin/blog/posts', blogPayload([
        'body' => '<p>'.$words.'</p><script>alert(1)</script><p onclick="x()">Hi <a href="javascript:alert(1)">bad</a> <iframe src="https://evil.test"></iframe></p>',
    ]))->assertCreated();

    $post = BlogPost::query()->findOrFail($response->json('id'));
    expect($post->slug)->toBe('best-educational-toys-for-3-5-year-olds')
        ->and($post->body)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('iframe')
        ->and($post->body)->toContain('Hi')
        ->and($post->reading_minutes)->toBe(3) // 451 words / 200 wpm → 3
        ->and($post->reading_minutes_bn)->toBe(1)
        ->and($post->status)->toBe(PostStatus::Draft)
        ->and($post->published_at)->toBeNull();

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:blog', 'content:post:'.$post->id, 'post:'.$post->slug]);
});

it('transliterates a Bangla-only title into an ASCII slug shared by both locales', function (): void {
    $response = $this->postJson('/api/v1/admin/blog/posts', blogPayload(['title' => 'খেলনা গাড়ি', 'title_bn' => 'খেলনা গাড়ি']))->assertCreated();

    expect($response->json('slug'))->toMatch('/^[a-z0-9-]+$/');
});

it('publishes now and keeps the original date on a later re-save', function (): void {
    Carbon::setTestNow('2026-11-20 06:00:00');
    $id = $this->postJson('/api/v1/admin/blog/posts', blogPayload(['status' => 'published']))->assertCreated()->json('id');

    Carbon::setTestNow('2026-11-22 06:00:00');
    $this->putJson("/api/v1/admin/blog/posts/{$id}", blogPayload(['status' => 'published', 'title' => 'Renamed']))->assertOk()
        ->assertJsonPath('published_at', '2026-11-20T06:00:00+00:00')
        ->assertJsonPath('is_live', true);
});

it('schedules in Dhaka time and stores the instant as UTC', function (): void {
    Carbon::setTestNow('2026-11-20 06:00:00');

    $response = $this->postJson('/api/v1/admin/blog/posts', blogPayload(['status' => 'scheduled', 'publish_at' => '2026-12-01 00:00']))->assertCreated();

    $response->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('published_at', '2026-11-30T18:00:00+00:00')
        ->assertJsonPath('published_at_dhaka', '2026-12-01 00:00')
        ->assertJsonPath('is_live', false);

    $this->postJson('/api/v1/admin/blog/posts', blogPayload(['status' => 'scheduled', 'publish_at' => '2026-12-01T00:00:00+06:00']))->assertCreated()
        ->assertJsonPath('published_at', '2026-11-30T18:00:00+00:00');
});

it('refuses a schedule in the past or without a time', function (array $overrides): void {
    Carbon::setTestNow('2026-11-20 06:00:00'); // 12:00 Dhaka

    $this->postJson('/api/v1/admin/blog/posts', blogPayload(['status' => 'scheduled', ...$overrides]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'publish_at');
})->with([
    'earlier today, Dhaka' => [['publish_at' => '2026-11-20 11:59']],
    'missing' => [[]],
    'wrong format' => [['publish_at' => '2026-11-21T10:00:00Z']],
]);

it('records slug history only when a live post changes slug', function (): void {
    $draft = BlogKit::post(['slug' => 'draft-one', 'status' => PostStatus::Draft, 'published_at' => null]);
    $this->putJson("/api/v1/admin/blog/posts/{$draft->id}", blogPayload(['slug' => 'draft-two']))->assertOk();
    expect(SlugHistory::query()->count())->toBe(0);

    $live = BlogKit::post(['slug' => 'old-guide']);
    $this->putJson("/api/v1/admin/blog/posts/{$live->id}", blogPayload(['slug' => 'new-guide', 'status' => 'published']))->assertOk();
    $this->putJson("/api/v1/admin/blog/posts/{$live->id}", blogPayload(['slug' => 'newest-guide', 'status' => 'published']))->assertOk();

    expect(SlugHistory::query()->where('sluggable_type', 'blog_post')->orderBy('id')->get(['old_slug', 'new_slug'])->toArray())->toBe([
        ['old_slug' => 'old-guide', 'new_slug' => 'newest-guide'],
        ['old_slug' => 'new-guide', 'new_slug' => 'newest-guide'],
    ]);

    // Taking an old slug back makes it live again: no redirect from it any more.
    $this->putJson("/api/v1/admin/blog/posts/{$live->id}", blogPayload(['slug' => 'old-guide', 'status' => 'published']))->assertOk();
    expect(SlugHistory::query()->where('old_slug', 'old-guide')->exists())->toBeFalse();
});

it('saves seo_meta per locale and returns it with the checklist', function (): void {
    $response = $this->postJson('/api/v1/admin/blog/posts', blogPayload([
        'seo' => ['title' => '10 Best Educational Toys', 'title_bn' => 'সেরা ১০ খেলনা', 'description' => 'Picks.', 'robots' => 'noindex,follow'],
    ]))->assertCreated();

    $response->assertJsonPath('seo.title', '10 Best Educational Toys')->assertJsonPath('seo.robots', 'noindex,follow')
        ->assertJsonPath('checklist.has_bangla', true);
    expect(SeoMeta::query()->where('seoable_type', 'blog_post')->where('seoable_id', $response->json('id'))->value('title_bn'))->toBe('সেরা ১০ খেলনা');
});

it('saves a buying guide with sections and picks, drops them when the type becomes article', function (): void {
    $toy = BlogKit::stockedProduct('wooden-puzzle');
    $draftToy = App\Domain\Catalog\Models\Product::factory()->draft()->create(['slug' => 'draft-toy']);

    $response = $this->postJson('/api/v1/admin/blog/posts', blogPayload([
        'type' => 'buying_guide',
        'guide_age_band' => '24-48',
        'guide_budget_min' => '500',
        'guide_budget_max' => '1500.50',
        'sections' => [[
            'heading' => 'Under ৳1,000',
            'heading_bn' => '৳1,000-এর নিচে',
            'body' => '<p>Small budgets.</p><script>x</script>',
            'budget_max' => '1000',
            'picks' => [['product_id' => $toy->id, 'note' => 'Thick pieces'], ['product_id' => $draftToy->id]],
        ]],
        'related_product_ids' => [$toy->id],
    ]))->assertCreated();

    $response->assertJsonPath('guide_budget_max', '1500.50')
        ->assertJsonPath('sections.0.body', '<p>Small budgets.</p>')
        ->assertJsonPath('sections.0.picks.0.visible', true)
        ->assertJsonPath('sections.0.picks.1.visible', false)
        ->assertJsonPath('related_products.0.product_id', $toy->id)
        ->assertJsonPath('checklist.product_links', 2);

    $id = $response->json('id');
    $this->putJson("/api/v1/admin/blog/posts/{$id}", blogPayload(['type' => 'article', 'guide_age_band' => '24-48']))->assertOk()
        ->assertJsonPath('sections', [])->assertJsonPath('guide_age_band', null);
    BlogKit::assertStockInvariant();
});

it('rejects an inverted budget', function (): void {
    $this->postJson('/api/v1/admin/blog/posts', blogPayload(['type' => 'buying_guide', 'guide_budget_min' => '2000', 'guide_budget_max' => '1000']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'guide_budget_max');
});

it('deletes only drafts', function (): void {
    $live = BlogKit::post();
    $draft = BlogKit::post(['status' => PostStatus::Draft, 'published_at' => null]);

    $this->deleteJson("/api/v1/admin/blog/posts/{$live->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/blog/posts/{$draft->id}")->assertNoContent();
    expect(BlogPost::query()->pluck('id')->all())->toBe([$live->id]);
});

it('lists posts with an allowlisted filter and sort, and refuses others', function (): void {
    BlogKit::post(['title' => 'Alpha', 'slug' => 'alpha']);
    BlogKit::post(['title' => 'Beta', 'slug' => 'beta', 'status' => PostStatus::Draft, 'published_at' => null]);

    $this->getJson('/api/v1/admin/blog/posts?filter[status]=draft')->assertOk()->assertJsonPath('data.0.slug', 'beta')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/blog/posts?filter[search]=alp')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/blog/posts?filter[status]=gone')->assertStatus(400);
    $this->getJson('/api/v1/admin/blog/posts?filter[views]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/blog/posts?sort=view_count')->assertStatus(400);
});

it('issues a preview link the storefront API accepts for a draft', function (): void {
    $draft = BlogKit::post(['slug' => 'secret-draft', 'status' => PostStatus::Draft, 'published_at' => null]);

    $link = $this->postJson("/api/v1/admin/blog/posts/{$draft->id}/preview-link")->assertOk();
    expect($link->json('path'))->toStartWith('/blog/preview/blog_post.'.$draft->id.'.')
        ->and($link->json('path_bn'))->toStartWith('/bn/blog/preview/');

    $this->withToken(BlogKit::token())->getJson('/api/v1/storefront/blog/preview/'.$link->json('token'))
        ->assertOk()->assertJsonPath('slug', 'secret-draft');
    $this->withToken(BlogKit::token())->getJson('/api/v1/storefront/blog/posts/secret-draft')->assertNotFound();
});

it('changes only the seo keys it is sent, leaving the full SEO panel fields alone', function (): void {
    $post = BlogKit::post();
    SeoMeta::query()->create(['seoable_type' => 'blog_post', 'seoable_id' => $post->id, 'og_title' => 'Panel OG title', 'title' => 'Old']);

    $this->putJson("/api/v1/admin/blog/posts/{$post->id}", blogPayload(['status' => 'published', 'seo' => ['title' => 'New meta title']]))->assertOk();

    $meta = SeoMeta::query()->where('seoable_type', 'blog_post')->where('seoable_id', $post->id)->firstOrFail();
    expect($meta->title)->toBe('New meta title')->and($meta->og_title)->toBe('Panel OG title');
});
