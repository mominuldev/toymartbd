<?php

declare(strict_types=1);

use App\Domain\Cart\Actions\PruneExpiredCarts;
use App\Domain\Cart\Models\Cart;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
 * SYSTEM-ARCHITECTURE §7 scheduled tasks that were not scheduled (P7 follow-up): search lag check,
 * nightly reindex, stale cart cleanup — and the sitemap, which was already.
 */

function scheduled(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(static fn (Event $e): bool => str_contains((string) $e->command, $needle) || str_contains((string) $e->description, $needle));
}

it('schedules the search index-lag check every five minutes', function (): void {
    $event = scheduled('toymart:search:lag-check');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules the nightly search reindex at 04:30 Dhaka', function (): void {
    $event = scheduled('toymart:search:reindex');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Dhaka');
});

it('schedules stale cart cleanup every ten minutes', function (): void {
    $event = scheduled('toymart:carts:prune');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/10 * * * *');
});

it('still schedules the sitemap rebuild at 03:00 Dhaka', function (): void {
    $event = scheduled('GenerateSitemaps');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Dhaka');
});

it('prunes only long-expired, never-ordered carts without recovery history', function (): void {
    $stale = Cart::factory()->create(['expires_at' => now()->subDays(PruneExpiredCarts::GRACE_DAYS + 1)]);
    $recent = Cart::factory()->create(['expires_at' => now()->subDays(PruneExpiredCarts::GRACE_DAYS - 1)]);
    $open = Cart::factory()->create();
    $converted = Cart::factory()->create(['expires_at' => now()->subDays(60)]);
    $converted->forceFill(['converted_at' => now()->subDays(59), 'order_id' => 123])->save();
    $recovered = Cart::factory()->create(['expires_at' => now()->subDays(60)]);
    DB::table('cart_recoveries')->insert(['cart_id' => $recovered->id, 'status' => 'skipped', 'locale' => 'en', 'abandoned_at' => now()->subDays(61), 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('toymart:carts:prune')->assertSuccessful();

    expect(Cart::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $open->id, $converted->id, $recovered->id])->sort()->values()->all())
        ->and(Cart::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(DB::table('cart_recoveries')->where('cart_id', $recovered->id)->exists())->toBeTrue();
});

it('bounds one run by its limit', function (): void {
    Cart::factory()->count(3)->create(['expires_at' => now()->subDays(30)]);

    expect(app(PruneExpiredCarts::class)->handle(2))->toBe(2)
        ->and(Cart::query()->count())->toBe(1);
});
