<?php

declare(strict_types=1);

use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Scheduled content is ONE every-minute sweep (`toymart:content:revalidate-scheduled`): homepage
 * CMS blocks, banners and static pages. There used to be two commands running every minute
 * (`…-schedule` for blocks, `…-scheduled` for banners/pages); they were merged.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

it('schedules exactly one scheduled-content sweep, every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn ($e): bool => str_contains((string) $e->command, 'toymart:content:revalidate'));

    expect($events)->toHaveCount(1)
        ->and((string) $events->first()->command)->toContain('toymart:content:revalidate-scheduled')
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and(array_key_exists('toymart:content:revalidate-schedule', Artisan::all()))->toBeFalse();
});

it('purges homepage blocks, banners and pages whose boundary passed — in one run', function (): void {
    CarbonImmutable::setTestNow('2026-12-01T00:00:00Z');
    Cache::flush();
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful(); // sets the window start

    CmsKit::block('faq', [], ['starts_at' => '2026-12-01 00:01:00', 'ends_at' => '2026-12-01 05:00:00']);
    app(PublishLayout::class)->handle(BlockPlacement::Home, null);
    $this->postJson('/api/v1/admin/content/banners', [
        'placement' => 'announcement_bar', 'name' => 'Winter sale',
        'title' => 'Free delivery in Dhaka', 'title_bn' => 'ঢাকায় ফ্রি ডেলিভারি',
        'starts_at' => '2026-12-01T06:01:00+06:00',
    ])->assertCreated();
    $this->postJson('/api/v1/admin/content/pages', [
        'title' => 'Winter returns', 'title_bn' => 'শীতের রিটার্ন', 'content' => '<p>30 days.</p>', 'content_bn' => '<p>৩০ দিন।</p>',
        'template' => 'default', 'status' => 'published', 'published_at' => '2026-12-01T06:01:00+06:00',
    ])->assertCreated();

    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:02:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();

    $sent = Queue::pushed(RevalidateStorefrontTags::class)->flatMap(static fn (RevalidateStorefrontTags $job): array => $job->tags)->unique()->values()->all();
    expect($sent)->toContain('content:home', 'content:banners', 'content:page:winter-returns', 'page:winter-returns');

    // Nothing passes in the next minute: nothing is purged.
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01T00:03:00Z'));
    $this->artisan('toymart:content:revalidate-scheduled')->assertSuccessful();
    Queue::assertNotPushed(RevalidateStorefrontTags::class);
    CarbonImmutable::setTestNow();
});
