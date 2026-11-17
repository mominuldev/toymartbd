<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Tests\Support\RoleMatrix;

/*
 * Analytics › Live: server-clock windows (30 minutes), Dhaka-date orders today incl. a
 * UTC-vs-Dhaka boundary, "vs this time yesterday", a PII-free feed, Dhaka-hour buckets,
 * the 403 role matrix and the no-parameters allowlist.
 *
 * Now = 2026-11-17 08:30 UTC = 14:30 Dhaka. Dhaka "today" began 2026-11-16 18:00 UTC.
 */

function liveOrder(string $placedUtc, string $total, array $attributes = []): Order
{
    return Order::factory()->total($total)->create([
        'customer_id' => Customer::factory()->create()->id,
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        ...$attributes,
    ]);
}

/** @param  array<string, mixed>  $payload */
function liveEvent(string $name, string $createdUtc, ?string $session, array $payload = [], ?string $value = null): TrackingEvent
{
    $at = CarbonImmutable::parse($createdUtc, 'UTC');

    return TrackingEvent::query()->create([
        'event_id' => (string) Str::uuid(),
        'event_name' => $name,
        'source' => 'browser',
        'session_id' => $session,
        'value' => $value,
        'currency' => $value === null ? null : 'BDT',
        'payload' => $payload,
        'consent_state' => ['analytics' => true, 'ads' => false, 'mode' => 'opt_in', 'policy_version' => '2026-11'],
        'occurred_at' => $at,
        'created_at' => $at,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 08:30:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole(Role::Manager)->create()->refresh();

    $this->teddy = Product::factory()->create(['name' => 'Plush Teddy Bear', 'name_bn' => 'টেডি বিয়ার']);
    $this->teddySku = $this->teddy->variants()->firstOrFail()->sku;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function liveTeddyItem(string $sku, int $quantity = 1): array
{
    return ['items' => [['item_id' => $sku, 'item_name' => 'Plush Teddy Bear', 'price' => '1450.00', 'quantity' => $quantity]], 'event_source_url' => 'https://toymartbd.com/products/plush-teddy-bear'];
}

it('counts active and at-checkout sessions over the last 30 minutes of the server clock', function (): void {
    $item = liveTeddyItem($this->teddySku);
    liveEvent('view_item', '2026-11-17 08:20:00', '1763360001', $item);           // s1 active
    liveEvent('add_to_cart', '2026-11-17 08:25:00', '1763360001', $item);
    liveEvent('begin_checkout', '2026-11-17 08:22:00', '1763360002', $item, '1450.00'); // s2 at checkout
    liveEvent('begin_checkout', '2026-11-17 08:10:00', '1763360003', $item, '1450.00'); // s3 then ordered
    liveEvent('view_item', '2026-11-17 07:50:00', '1763360004', $item);           // 40 min ago: out
    liveEvent('begin_checkout', '2026-11-17 07:55:00', '1763360005', $item);      // 35 min ago: out
    liveEvent('add_to_cart', '2026-11-17 08:28:00', null, $item);                 // no GA4 session: not a session
    liveEvent('view_item', '2026-11-17 08:15:00', '1763360006', $item);           // s6 active

    $order = liveOrder('2026-11-17 08:15:00', '1450.00');
    OrderTrackingContext::query()->create(['order_id' => $order->id, 'ga_session_id' => '1763360003', 'created_at' => CarbonImmutable::parse('2026-11-17 08:15:00', 'UTC')]);

    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live')
        ->assertOk()
        ->assertJsonPath('generated_at', '2026-11-17T08:30:00Z')
        ->assertJsonPath('today', '2026-11-17')
        ->assertJsonPath('window_minutes', 30)
        ->assertJsonPath('now.active_sessions', 4)
        ->assertJsonPath('now.checkout_sessions', 1)
        ->assertJsonPath('tracking.consent_gated', true)
        ->assertJsonPath('viewing.pages_available', false)
        ->assertJsonPath('viewing.products.0.name', 'Plush Teddy Bear')
        ->assertJsonPath('viewing.products.0.name_bn', 'টেডি বিয়ার')
        ->assertJsonPath('viewing.products.0.sessions', 2);

    // At 08:47 the window starts 08:17: s3 and s6 have aged out; s1 and s2 remain.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 08:47:00', 'UTC'));
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live')
        ->assertOk()
        ->assertJsonPath('now.active_sessions', 2)
        ->assertJsonPath('now.checkout_sessions', 1);
});

it('counts orders today by Dhaka date, against the same time yesterday, in Dhaka-hour buckets', function (): void {
    // 00:30 Dhaka on the 17th, though the UTC date is still the 16th: today, hour 0.
    liveOrder('2026-11-16 18:30:00', '1000.00');
    // 23:50 Dhaka on the 16th — after "this time yesterday" (14:30), so not in the comparison.
    liveOrder('2026-11-16 17:50:00', '999.00');
    // 09:00 and 14:26 Dhaka today.
    liveOrder('2026-11-17 03:00:00', '2350.00');
    $chattogram = liveOrder('2026-11-17 08:26:00', '1520.00', ['shipping_district' => 'Chattogram']);
    OrderItem::factory()->create(['order_id' => $chattogram->id, 'product_id' => $this->teddy->id, 'product_name' => 'Plush Teddy Bear', 'product_name_bn' => 'টেডি বিয়ার']);
    // Yesterday before 14:30 Dhaka: 00:10 and 11:00. 15:00 Dhaka yesterday is after it.
    liveOrder('2026-11-15 18:10:00', '500.00');
    liveOrder('2026-11-16 05:00:00', '500.00');
    liveOrder('2026-11-16 09:00:00', '500.00');

    $response = $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live')
        ->assertOk()
        ->assertJsonPath('orders_today.count', 3)
        ->assertJsonPath('orders_today.value', '4870.00')
        ->assertJsonPath('orders_today.same_time_yesterday', 2)
        ->assertJsonPath('orders_today.difference', 1)
        ->assertJsonPath('last_order.number', $chattogram->number)
        ->assertJsonPath('last_order.minutes_ago', 4)
        ->assertJsonPath('last_order.district', 'Chattogram')
        ->assertJsonPath('last_order.product_name', 'Plush Teddy Bear')
        ->assertJsonPath('last_order.amount', '1520.00');

    $hourly = $response->json('hourly');
    expect($hourly)->toHaveCount(24)
        ->and(array_column($hourly, 'hour'))->toBe(range(0, 23))
        ->and($hourly[0])->toBe(['hour' => 0, 'orders' => 1, 'value' => '1000.00'])
        ->and($hourly[9])->toBe(['hour' => 9, 'orders' => 1, 'value' => '2350.00'])
        ->and($hourly[14])->toBe(['hour' => 14, 'orders' => 1, 'value' => '1520.00'])
        ->and(array_sum(array_column($hourly, 'orders')))->toBe(3);
});

it('merges orders and funnel events into a newest-first feed with no shopper PII', function (): void {
    $order = liveOrder('2026-11-17 08:26:00', '1520.00', [
        'shipping_name' => 'Rahima Khatun',
        'shipping_phone' => '+8801712345678',
        'shipping_address' => 'House 12, Road 5, Agrabad',
        'shipping_landmark' => 'Near the mosque',
        'shipping_district' => 'Chattogram',
    ]);
    liveEvent('add_to_cart', '2026-11-17 08:25:00', '1763360001', liveTeddyItem($this->teddySku), '1450.00');
    liveEvent('begin_checkout', '2026-11-17 08:23:00', '1763360002', liveTeddyItem($this->teddySku, 2), '2900.00');
    liveEvent('view_item', '2026-11-17 08:22:00', '1763360003', liveTeddyItem($this->teddySku));
    // A server purchase row duplicates the order: never a feed item of its own.
    TrackingEvent::query()->create(['event_id' => (string) Str::uuid(), 'event_name' => 'purchase', 'source' => 'server', 'order_id' => $order->id, 'payload' => ['transaction_id' => $order->number], 'created_at' => CarbonImmutable::parse('2026-11-17 08:26:00', 'UTC')]);
    // Older than 24 hours: not in the feed.
    liveEvent('view_item', '2026-11-16 08:00:00', '1763360009', liveTeddyItem($this->teddySku));

    $response = $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live')->assertOk();

    expect(array_column($response->json('feed'), 'kind'))->toBe(['order', 'add_to_cart', 'begin_checkout', 'view_item']);
    $response->assertJsonPath('feed.0.order_number', $order->number)
        ->assertJsonPath('feed.0.amount', '1520.00')
        ->assertJsonPath('feed.0.district', 'Chattogram')
        ->assertJsonPath('feed.0.minutes_ago', 4)
        ->assertJsonPath('feed.1.product_name', 'Plush Teddy Bear')
        ->assertJsonPath('feed.2.items', 2)
        ->assertJsonPath('feed.2.amount', '2900.00')
        ->assertJsonPath('feed.3.minutes_ago', 8);

    $body = $response->getContent();
    foreach (['Rahima', 'Khatun', '01712345678', '8801712345678', '12345678', 'House 12', 'Agrabad', 'mosque', '1763360001', '1763360002', 'ga_session_id'] as $secret) {
        expect(str_contains((string) $body, $secret))->toBeFalse("leaked {$secret}");
    }
});

it('says honestly that nothing has happened yet', function (): void {
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live')
        ->assertOk()
        ->assertJsonPath('now.active_sessions', 0)
        ->assertJsonPath('now.checkout_sessions', 0)
        ->assertJsonPath('orders_today.count', 0)
        ->assertJsonPath('orders_today.value', '0.00')
        ->assertJsonPath('last_order', null)
        ->assertJsonPath('feed', [])
        ->assertJsonPath('viewing.products', []);
});

it('guards GET /analytics/live for every role', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/analytics/live'));
})->with(RoleMatrix::dataset(Permission::ReportsView));

it('refuses a guest with 401', function (): void {
    $this->getJson('/api/v1/admin/analytics/live')->assertUnauthorized();
});

it('rejects any query parameter with 400', function (string $query): void {
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/live?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'bad-request'));
})->with(['range=today', 'filter[from]=2026-11-01', 'window=60', 'page=2']);
