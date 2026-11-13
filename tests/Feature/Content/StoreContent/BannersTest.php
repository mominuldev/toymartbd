<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\Banner;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Banners (Phase 7): placement rules (rule 25b: only the text-only info strip on cart and
 * checkout), Dhaka-time scheduling, category targeting, priority, and revalidation.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

function scBannerToken(): string
{
    return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
}

/** @param  array<string, mixed>  $overrides */
function scBanner(array $overrides = []): array
{
    return array_merge([
        'placement' => 'announcement_bar',
        'name' => 'Winter sale',
        'title' => 'Free delivery in Dhaka over 2000 Tk',
        'title_bn' => 'ঢাকায় 2000 টাকার বেশি অর্ডারে ফ্রি ডেলিভারি',
    ], $overrides);
}

it('stores a Dhaka-time schedule as UTC and shows it only inside the window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:00:00Z'));

    $this->postJson('/api/v1/admin/content/banners', scBanner([
        'starts_at' => '2026-12-16T00:00:00+06:00',
        'ends_at' => '2026-12-17T00:00:00+06:00',
    ]))->assertCreated()
        ->assertJsonPath('starts_at', '2026-12-15T18:00:00Z')
        ->assertJsonPath('ends_at', '2026-12-16T18:00:00Z')
        ->assertJsonPath('state', 'scheduled');

    $token = scBannerToken();
    $url = '/api/v1/storefront/banners?placement=announcement_bar';

    $this->travelTo(CarbonImmutable::parse('2026-12-15T17:59:59Z'));
    expect($this->withToken($token)->getJson($url)->json('data'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-12-15T18:00:00Z'));
    $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.0.title_bn', 'ঢাকায় 2000 টাকার বেশি অর্ডারে ফ্রি ডেলিভারি');

    $this->travelTo(CarbonImmutable::parse('2026-12-16T18:00:00Z'));
    expect($this->withToken($token)->getJson($url)->json('data'))->toBe([]);
});

it('refuses an end before the start', function (): void {
    $this->postJson('/api/v1/admin/content/banners', scBanner(['starts_at' => '2026-12-16T00:00:00+06:00', 'ends_at' => '2026-12-15T00:00:00+06:00']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'ends_at');
});

it('keeps the info strip text-only and linked only to store pages', function (): void {
    $media = Media::factory()->create();

    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'info_strip', 'media_id' => $media->id]))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'media_id');
    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'info_strip', 'link_url' => '/products/lego-city']))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'link_url');
    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'info_strip', 'link_url' => '/pages/delivery-policy']))
        ->assertCreated();
});

it('allows only the info strip on cart and checkout', function (): void {
    foreach (['announcement_bar', 'popup', 'category_page', 'info_strip'] as $placement) {
        $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => $placement, 'name' => $placement]))->assertCreated();
    }

    $token = scBannerToken();
    foreach (['cart', 'checkout'] as $context) {
        foreach (['announcement_bar', 'popup', 'category_page'] as $placement) {
            expect($this->withToken($token)->getJson("/api/v1/storefront/banners?placement={$placement}&context={$context}")->assertOk()->json('data'))->toBe([]);
        }
        $this->withToken($token)->getJson("/api/v1/storefront/banners?placement=info_strip&context={$context}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.image', null);
    }

    $this->withToken($token)->getJson('/api/v1/storefront/banners?placement=popup')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/storefront/banners?placement=hero')->assertStatus(400);
});

it('targets category banners and orders by priority', function (): void {
    $puzzles = Category::factory()->create(['slug' => 'puzzles']);
    Category::factory()->create(['slug' => 'dolls']);
    $media = Media::factory()->create();

    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'category_page', 'name' => 'all', 'priority' => 5, 'media_id' => $media->id]))->assertCreated();
    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'category_page', 'name' => 'puzzles', 'category_id' => $puzzles->id]))->assertCreated();
    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'announcement_bar', 'category_id' => $puzzles->id]))
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'category_id');

    $token = scBannerToken();
    $puzzleIds = $this->withToken($token)->getJson('/api/v1/storefront/banners?placement=category_page&category=puzzles')->json('data.*.id');
    $dollIds = $this->withToken($token)->getJson('/api/v1/storefront/banners?placement=category_page&category=dolls')->json('data.*.id');

    $all = Banner::query()->where('name', 'all')->value('id');
    $only = Banner::query()->where('name', 'puzzles')->value('id');
    expect($puzzleIds)->toBe([$only, $all])->and($dollIds)->toBe([$all]);
    expect($this->withToken($token)->getJson('/api/v1/storefront/banners?placement=category_page&category=dolls')->json('data.0.image.url'))->toBeString();
});

it('refuses to delete a library image a banner uses', function (): void {
    $media = Media::factory()->create();
    $this->postJson('/api/v1/admin/content/banners', scBanner(['placement' => 'popup', 'media_id' => $media->id]))->assertCreated();

    auth()->forgetGuards();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $this->deleteJson("/api/v1/admin/media/{$media->id}")->assertStatus(409);
    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('purges content:banners on save and when a schedule boundary passes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:00:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();

    Queue::fake();
    $this->postJson('/api/v1/admin/content/banners', scBanner(['starts_at' => '2026-12-01T06:05:00+06:00']))->assertCreated();
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:banners']);

    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:03:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:06:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:banners']);
});
