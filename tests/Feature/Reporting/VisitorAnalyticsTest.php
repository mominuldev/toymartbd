<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RoleMatrix;

/*
 * Analytics › Visitors & funnel: distinct analytics-consented sessions per step over Dhaka
 * dates, the order steps joined through the checkout context, the previous-period delta, the
 * biggest drop chosen on the server, and nothing personal in the response (rule 20).
 */

const VISITORS_URL = '/api/v1/admin/analytics/visitors';

/** One browser event, as RecordTrackingEvent stores it. */
function visitorEvent(string $name, ?string $session, string $atUtc, array $skus = []): void
{
    $items = array_map(static fn (string $sku): array => ['item_id' => $sku, 'item_name' => 'Toy', 'price' => '1250.00', 'quantity' => 1], $skus);
    DB::table('tracking_events')->insert([
        'event_id' => (string) Str::uuid(),
        'event_name' => $name,
        'source' => 'browser',
        'session_id' => $session,
        'payload' => json_encode(['event_source_url' => 'https://toymartbd.test/products/x', 'items' => $items]),
        'consent_state' => json_encode(['analytics' => $session !== null, 'ads' => true, 'mode' => 'opt_in']),
        'occurred_at' => $atUtc,
        'created_at' => $atUtc,
    ]);
}

/** A web order whose checkout ran in the given GA session. */
function visitorOrder(Customer $customer, string $session, string $placedUtc, OrderStatus $status, ?string $deliveredUtc = null): Order
{
    $order = Order::factory()->status($status)->create([
        'customer_id' => $customer->id,
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        'delivered_at' => $deliveredUtc === null ? null : CarbonImmutable::parse($deliveredUtc, 'UTC'),
    ]);
    DB::table('order_tracking_contexts')->insert([
        'order_id' => $order->id,
        'client_ip' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 test',
        'ga_client_id' => '111.222',
        'ga_session_id' => $session,
        'consent' => json_encode(['analytics' => true, 'ads' => true]),
        'created_at' => $placedUtc,
    ]);

    return $order;
}

function visitorsManager(): User
{
    return User::factory()->withRole(Role::Manager)->create()->refresh();
}

beforeEach(function (): void {
    // Dhaka 2026-12-05 12:00 → 7d = 29 Nov – 5 Dec, previous = 22 – 28 Nov.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 06:00:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->puzzle = Product::factory()->create(['name' => 'Wooden Puzzle', 'name_bn' => 'কাঠের পাজল']);
    $this->doll = Product::factory()->create(['name' => 'Rag Doll', 'name_bn' => 'কাপড়ের পুতুল']);
    $puzzleSku = $this->puzzle->variants()->firstOrFail()->sku;
    $dollSku = $this->doll->variants()->firstOrFail()->sku;
    $this->customer = Customer::factory()->create(['phone' => '+8801712345678']);

    $day = '2026-12-03 04:00:00';
    // s1: the whole way, delivered. s2: placed, still open. s3: cart only. s4: two views. s5: a doll view.
    foreach (['1764730001', '1764730002', '1764730003', '1764730004'] as $s) {
        visitorEvent('view_item', $s, $day, [$puzzleSku]);
    }
    visitorEvent('view_item', '1764730004', $day, [$puzzleSku]);
    foreach (['1764730001', '1764730002', '1764730003'] as $s) {
        visitorEvent('add_to_cart', $s, $day, [$puzzleSku]);
    }
    foreach (['1764730001', '1764730002'] as $s) {
        visitorEvent('begin_checkout', $s, $day, [$puzzleSku]);
    }
    // 18:30 UTC on 28 Nov is 00:30 on 29 Nov in Dhaka — the current range's first day.
    visitorEvent('view_item', '1764730005', '2026-11-28 18:30:00', [$dollSku]);
    // Ads-only consent: no session id, so it is reported apart and never counted as a visit.
    visitorEvent('view_item', null, $day, [$puzzleSku]);

    visitorOrder($this->customer, '1764730001', '2026-12-03 05:00:00', OrderStatus::Delivered, '2026-12-04 08:00:00');
    visitorOrder($this->customer, '1764730002', '2026-12-03 05:10:00', OrderStatus::Confirmed);
    // A checkout whose session never showed up in the events: not part of this funnel.
    visitorOrder($this->customer, '1764739999', '2026-12-03 06:00:00', OrderStatus::Confirmed);

    // Previous range: 17:59 UTC on 28 Nov is 23:59 on 28 Nov in Dhaka.
    visitorEvent('view_item', '1764100001', '2026-11-28 17:59:00', [$puzzleSku]);
    visitorEvent('view_item', '1764100002', '2026-11-24 10:00:00', [$puzzleSku]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('counts each funnel step by distinct session over Dhaka dates', function (): void {
    $r = $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->assertOk();

    $r->assertJsonPath('counted', 'analytics_consented_sessions')
        ->assertJsonPath('range', ['preset' => '7d', 'from' => '2026-11-29', 'to' => '2026-12-05', 'days' => 7])
        ->assertJsonPath('comparison.from', '2026-11-22');

    $funnel = collect($r->json('funnel'))->keyBy('key');
    expect($funnel->keys()->all())->toBe(['visited', 'viewed_product', 'added_to_cart', 'reached_checkout', 'placed_order', 'delivered'])
        ->and($funnel->map(fn (array $s): int => $s['sessions'])->all())->toBe([
            'visited' => 5, 'viewed_product' => 5, 'added_to_cart' => 3, 'reached_checkout' => 2, 'placed_order' => 2, 'delivered' => 1,
        ])
        ->and($funnel['visited']['drop_percent'])->toBeNull()
        ->and($funnel['viewed_product']['drop_percent'])->toBe('0.00')
        ->and($funnel['added_to_cart']['drop_percent'])->toBe('40.00')
        ->and($funnel['reached_checkout']['drop_percent'])->toBe('33.33')
        ->and($funnel['delivered']['drop_percent'])->toBe('50.00')
        ->and($funnel['added_to_cart']['of_visits_percent'])->toBe('60.00');

    $r->assertJsonPath('summary.order_rate', '40.00')
        ->assertJsonPath('summary.cart_to_order_rate', '66.67')
        ->assertJsonPath('summary.delivered_rate', '50.00')
        ->assertJsonPath('summary.in_progress', 1)
        ->assertJsonPath('summary.orders_total', 3)
        ->assertJsonPath('tracking.events_without_session', 1)
        ->assertJsonPath('tracking.page_views_recorded', false)
        ->assertJsonPath('tracking.devices_recorded', false)
        ->assertJsonPath('tracking.places_recorded', false);
});

it('puts each visit and order on its Dhaka day', function (): void {
    $daily = collect($this->actingAs(visitorsManager())->getJson(VISITORS_URL)->json('daily'))->keyBy('date');

    expect($daily)->toHaveCount(7)
        ->and($daily['2026-11-29'])->toBe(['date' => '2026-11-29', 'visits' => 1, 'orders' => 0])
        ->and($daily['2026-12-03'])->toBe(['date' => '2026-12-03', 'visits' => 4, 'orders' => 2])
        ->and($daily['2026-12-05']['visits'])->toBe(0);
});

it('compares with the previous period', function (): void {
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->assertOk()
        ->assertJsonPath('previous.visits', 2)
        ->assertJsonPath('previous.order_rate', '0.00')
        ->assertJsonPath('deltas.visits', '150.00')
        ->assertJsonPath('deltas.placed', null)
        ->assertJsonPath('deltas.order_rate_points', '40.00')
        ->assertJsonPath('funnel.0.previous_sessions', 2)
        ->assertJsonPath('funnel.0.change', '150.00');
});

it('names the biggest drop among the shopping steps, never delivery', function (): void {
    // delivered drops 50% but is left out; the largest shopping drop is product view → cart (40%).
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->assertOk()
        ->assertJsonPath('biggest_drop', ['from' => 'viewed_product', 'to' => 'added_to_cart', 'percent' => '40.00']);
});

it('ranks the most viewed products by their SKU, with Bangla names', function (): void {
    $top = $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->json('top_products');

    expect($top)->toHaveCount(2)
        ->and($top[0])->toBe(['product_id' => $this->puzzle->id, 'name' => 'Wooden Puzzle', 'name_bn' => 'কাঠের পাজল', 'views' => 5, 'add_to_carts' => 3, 'cart_rate' => '60.00'])
        ->and($top[1]['name'])->toBe('Rag Doll')
        ->and($top[1]['views'])->toBe(1);
});

it('takes a custom Dhaka range', function (): void {
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL.'?range=custom&filter[from]=2026-11-22&filter[to]=2026-11-28')->assertOk()
        ->assertJsonPath('range', ['preset' => 'custom', 'from' => '2026-11-22', 'to' => '2026-11-28', 'days' => 7])
        ->assertJsonPath('summary.visits', 2)
        ->assertJsonPath('summary.placed', 0);

    // Dates alone imply custom.
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL.'?filter[from]=2026-12-03&filter[to]=2026-12-03')->assertOk()
        ->assertJsonPath('range.preset', 'custom')
        ->assertJsonPath('summary.visits', 4);
});

it('returns zeros and nulls for a period with no events', function (): void {
    $r = $this->actingAs(visitorsManager())->getJson(VISITORS_URL.'?filter[from]=2026-10-01&filter[to]=2026-10-07')->assertOk();

    $r->assertJsonPath('summary.visits', 0)
        ->assertJsonPath('summary.order_rate', null)
        ->assertJsonPath('summary.cart_to_order_rate', null)
        ->assertJsonPath('summary.delivered_rate', null)
        ->assertJsonPath('biggest_drop', null)
        ->assertJsonPath('top_products', [])
        ->assertJsonPath('tracking.events_recorded', 0);
    expect(collect($r->json('funnel'))->pluck('drop_percent')->filter()->all())->toBe([])
        ->and($r->json('daily'))->toHaveCount(7);
});

it('says when the checkout link has been pruned for an old range', function (): void {
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL.'?filter[from]=2026-06-01&filter[to]=2026-06-07')->assertOk()
        ->assertJsonPath('tracking.order_link_complete', false)
        ->assertJsonPath('tracking.order_link_retention_days', 90);
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->assertJsonPath('tracking.order_link_complete', true);
});

it('returns nothing personal', function (): void {
    $body = $this->actingAs(visitorsManager())->getJson(VISITORS_URL)->assertOk()->getContent();

    expect($body)->not->toContain('1764730001')
        ->and($body)->not->toContain('session_id')
        ->and($body)->not->toContain('12345678')
        ->and($body)->not->toContain('203.0.113.9')
        ->and($body)->not->toContain('Mozilla')
        ->and($body)->not->toContain('111.222');
});

it('rejects an unlisted parameter or filter, a bad preset and a half range with 400', function (string $query): void {
    $this->actingAs(visitorsManager())->getJson(VISITORS_URL.'?'.$query)->assertStatus(400)
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'bad-request'));
})->with([
    'unlisted filter' => 'filter[device]=mobile',
    'unlisted param' => 'group=day',
    'unknown preset' => 'range=forever',
    'half range' => 'range=custom&filter[from]=2026-11-01',
    'dates with a preset' => 'range=7d&filter[from]=2026-11-01&filter[to]=2026-11-02',
    'too long' => 'filter[from]=2025-01-01&filter[to]=2026-12-01',
]);

it('guards GET /analytics/visitors with reports.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(VISITORS_URL));
})->with(RoleMatrix::dataset(Permission::ReportsView));
