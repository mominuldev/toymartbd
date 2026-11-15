<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\OrderReturn;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Feature\ShipmentsBoard\ShipmentsBoardKit as Kit;
use Tests\Support\CourierFixtures;
use Tests\Support\RoleMatrix;

/*
 * Shipments & tracking board (Figma B · 08, docs/api/shipments-board.md): every figure is
 * computed server-side (rule 1) and bucketed by Dhaka date (rule 11); query strings are
 * allowlisted (rule 16); the customer phone is masked without customers.view_pii (rule 20);
 * every route has a 403 role matrix (rule 21).
 *
 * Clock: 2026-11-08 12:00 Dhaka = 06:00 UTC. Default range: 2026-11-02 … 2026-11-08 (Dhaka).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    CourierFixtures::provider('steadfast');
    CourierFixtures::provider('pathao', ['is_default' => false, 'base_charge_dhaka' => '60.00', 'base_charge_outside' => '110.00', 'included_weight_grams' => 2000]);
    $this->travelTo(Carbon::parse('2026-11-08 06:00:00', 'UTC'));
    $this->variant = OrderFixtures::stockedVariant(100, '1000.00', '600.00');
});

/** Dhaka metro (district 47) and Chattogram (district 8, outside). */
function sbOrder(object $test, ?int $district = null): App\Domain\Ordering\Models\Order
{
    return Kit::order($test->variant, $district === null ? [] : ['shipping_district_id' => $district]);
}

function sbOutcomes(object $test): void
{
    // In transit now, booked 2 days ago, ৳2,570 COD.
    Kit::shipment(sbOrder($test), ShipmentStatus::InTransit, ['booked_at' => '2026-11-06 06:00:00', 'cod_amount' => '2570.00']);
    // Two bulk-submitted parcels without an id: 7 h and 1 h old.
    Kit::shipment(sbOrder($test), ShipmentStatus::Submitted, ['pickup_requested_at' => '2026-11-07 23:00:00', 'cod_amount' => '1930.00']);
    Kit::shipment(sbOrder($test), ShipmentStatus::Submitted, ['pickup_requested_at' => '2026-11-08 05:00:00']);
    // Delivered in 1.0 day, Dhaka metro.
    Kit::shipment(sbOrder($test, 47), ShipmentStatus::Delivered, ['booked_at' => '2026-11-05 10:00:00', 'delivered_at' => '2026-11-06 10:00:00']);
    // Delivered in 3.0 days, outside Dhaka.
    Kit::shipment(sbOrder($test, 8), ShipmentStatus::Delivered, ['booked_at' => '2026-11-03 06:00:00', 'delivered_at' => '2026-11-06 06:00:00']);
    // Delivered 2026-11-01 18:30 UTC = 2026-11-02 00:30 Dhaka: inside the range by Dhaka date; 1.0 day, no district id.
    Kit::shipment(sbOrder($test), ShipmentStatus::Delivered, ['booked_at' => '2026-10-31 18:30:00', 'delivered_at' => '2026-11-01 18:30:00']);
    // Delivered 2026-11-01 17:59 UTC = 2026-11-01 23:59 Dhaka: outside the range.
    Kit::shipment(sbOrder($test), ShipmentStatus::Delivered, ['booked_at' => '2026-10-30 06:00:00', 'delivered_at' => '2026-11-01 17:59:00']);
    // Returned on 2026-11-07.
    Kit::shipment(sbOrder($test), ShipmentStatus::Returned, ['booked_at' => '2026-11-04 06:00:00', 'returned_at' => '2026-11-07 06:00:00', 'charge' => '70.00', 'return_charge' => '50.00', 'total_cost' => '120.00']);
}

it('computes the stat tiles from real rows, by Dhaka date', function (): void {
    Support::actingAs(Role::SuperAdmin);
    sbOutcomes($this);

    $this->getJson('/api/v1/admin/shipments/board/stats')
        ->assertOk()
        ->assertJsonPath('from', '2026-11-02')
        ->assertJsonPath('to', '2026-11-08')
        ->assertJsonPath('in_transit.count', 1)
        ->assertJsonPath('in_transit.cod_on_road', '2570.00')
        ->assertJsonPath('submitted.count', 2)
        ->assertJsonPath('submitted.oldest_age_minutes', 420)
        ->assertJsonPath('submitted.chase_after_minutes', 360)
        ->assertJsonPath('submitted.overdue', true)
        ->assertJsonPath('success.delivered', 3)
        ->assertJsonPath('success.returned', 1)
        ->assertJsonPath('success.rate', '75.0')
        ->assertJsonPath('days_to_deliver.average', '1.7')
        ->assertJsonPath('days_to_deliver.timed', 3)
        ->assertJsonPath('days_to_deliver.by_zone', [
            ['zone' => 'inside_dhaka_metro', 'average' => '1.0', 'timed' => 1],
            ['zone' => 'outside_dhaka', 'average' => '3.0', 'timed' => 1],
        ]);

    // A one-day range on 2026-11-01 (Dhaka) holds only the 23:59 delivery.
    $this->getJson('/api/v1/admin/shipments/board/stats?filter[from]=2026-11-01&filter[to]=2026-11-01')
        ->assertOk()->assertJsonPath('success.delivered', 1)->assertJsonPath('success.rate', '100.0')
        // In transit and Submitted follow the range too (owner, 2026-11-15): none was handed over that day.
        ->assertJsonPath('in_transit.count', 0)->assertJsonPath('in_transit.cod_on_road', '0.00')
        ->assertJsonPath('submitted.count', 0)->assertJsonPath('submitted.oldest_at', null);

    // A parcel handed over before the range and still on the road is not counted in it.
    Kit::shipment(sbOrder($this), ShipmentStatus::InTransit, ['booked_at' => '2026-10-20 06:00:00', 'cod_amount' => '999.00']);
    $this->getJson('/api/v1/admin/shipments/board/stats')
        ->assertOk()->assertJsonPath('in_transit.count', 1)->assertJsonPath('in_transit.cod_on_road', '2570.00');
});

it('returns null figures — never zero — when nothing had an outcome', function (): void {
    Support::actingAs(Role::SuperAdmin);

    $this->getJson('/api/v1/admin/shipments/board/stats')
        ->assertOk()
        ->assertJsonPath('success.rate', null)
        ->assertJsonPath('days_to_deliver.average', null)
        ->assertJsonPath('days_to_deliver.by_zone', [])
        ->assertJsonPath('submitted.oldest_age_minutes', null)
        ->assertJsonPath('submitted.overdue', false);
});

it('lists the cohort with tab counts, a cost basis and the last courier event', function (): void {
    Support::actingAs(Role::SuperAdmin);
    sbOutcomes($this);
    $held = Kit::shipment(sbOrder($this, 47), ShipmentStatus::OnHold, ['booked_at' => '2026-11-07 06:00:00', 'delivery_attempts' => 1]);
    Kit::event($held, ShipmentStatus::OutForDelivery, '2026-11-07 08:00:00', 'webhook', 'Out_For_Delivery');
    Kit::event($held, ShipmentStatus::OnHold, '2026-11-07 12:00:00', 'poll', 'Delivery_Failed');
    // Never reached a courier: not on the board.
    Kit::shipment(sbOrder($this), ShipmentStatus::Failed, ['consignment_id' => null]);

    $response = $this->getJson('/api/v1/admin/shipments/board')->assertOk()
        ->assertJsonPath('meta.counts', [
            // Cohort = handed to the courier on a Dhaka date in range: the 2026-11-02 00:30 delivery was
            // handed over on 2026-11-01 (Dhaka) and the 10-30 one earlier, so neither is on this board.
            'all' => 7, 'submitted' => 2, 'in_transit' => 2, 'delivered' => 2, 'returned' => 1, 'failed_attempts' => 1,
        ])
        ->assertJsonPath('meta.from', '2026-11-02')
        ->assertJsonCount(7, 'data');

    $row = collect($response->json('data'))->firstWhere('id', $held->id);
    expect($row['last_event'])->toMatchArray(['status' => 'on_hold', 'provider_status' => 'Delivery_Failed', 'source' => 'poll'])
        // No recorded cost: an estimate from Steadfast's rate card for Dhaka metro, ≤ 1 kg: 70 + 1 % of ৳1,000.
        ->and($row['cost'])->toBe(['amount' => '80.00', 'basis' => 'estimate']);
    $returned = collect($response->json('data'))->firstWhere('status', 'returned');
    expect($returned['cost'])->toBe(['amount' => '120.00', 'basis' => 'recorded']);
    $submitted = collect($response->json('data'))->firstWhere('status', 'submitted');
    expect($submitted['consignment_id'])->toBeNull()
        // No district id on the order: no zone, no estimate — never a guess.
        ->and($submitted['cost'])->toBe(['amount' => null, 'basis' => null]);

    $this->getJson('/api/v1/admin/shipments/board?filter[tab]=failed_attempts')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $held->id);
    $this->getJson('/api/v1/admin/shipments/board?filter[tab]=delivered')->assertOk()->assertJsonCount(2, 'data');
});

it('prefers a matched settlement line as the actual cost', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $s = Kit::shipment(sbOrder($this), ShipmentStatus::Delivered, ['booked_at' => '2026-11-05 06:00:00', 'delivered_at' => '2026-11-06 06:00:00', 'charge' => '70.00', 'cod_fee' => '10.00', 'total_cost' => '80.00']);
    $settlementId = DB::table('courier_settlements')->insertGetId(sbSettlementRow($s->provider_id));
    DB::table('courier_settlement_items')->insert([
        'settlement_id' => $settlementId, 'shipment_id' => $s->id, 'order_id' => $s->order_id, 'consignment_id' => $s->consignment_id,
        'cod_amount' => '1000.00', 'charges' => '75.00', 'cod_fee' => '10.00', 'return_charge' => '0.00', 'net' => '915.00',
        'is_matched' => true, 'review_status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/v1/admin/shipments/board')->assertOk()
        ->assertJsonPath('data.0.cost', ['amount' => '85.00', 'basis' => 'settled']);
});

/** @return array<string, mixed> */
function sbSettlementRow(int $providerId): array
{
    $columns = Illuminate\Support\Facades\Schema::getColumns('courier_settlements');
    $row = ['provider_id' => $providerId, 'created_at' => now(), 'updated_at' => now()];
    foreach ($columns as $c) {
        if ($c['nullable'] || $c['default'] !== null || $c['auto_increment'] || array_key_exists($c['name'], $row)) {
            continue;
        }
        $row[$c['name']] = match (true) {
            str_contains($c['type_name'], 'date') || str_contains($c['type_name'], 'time') => now(),
            str_contains($c['type_name'], 'int') || str_contains($c['type_name'], 'decimal') || str_contains($c['type_name'], 'numeric') => 0,
            default => 'T-'.Illuminate\Support\Str::random(8),
        };
    }

    return $row;
}

it('filters by courier, search and range; refuses an unlisted filter or value with 400', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $order = sbOrder($this);
    $a = Kit::shipment($order, ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00'], 'pathao');
    Kit::shipment(sbOrder($this), ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00']);
    Kit::shipment(sbOrder($this), ShipmentStatus::Booked, ['booked_at' => '2026-10-20 06:00:00']);

    $this->getJson('/api/v1/admin/shipments/board?filter[provider]=pathao')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    $this->getJson('/api/v1/admin/shipments/board?filter[search]='.$a->number)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/shipments/board?filter[search]='.$order->number)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    $this->getJson('/api/v1/admin/shipments/board?filter[from]=2026-10-01&filter[to]=2026-11-08')->assertOk()->assertJsonCount(3, 'data');

    foreach ([
        'filter[status]=booked', 'filter[tab]=nope', 'filter[provider]=redx', 'filter[from]=2026-13-01',
        'sort=-id', 'filter[provider][]=pathao',
    ] as $qs) {
        $this->getJson('/api/v1/admin/shipments/board?'.$qs)->assertStatus(400)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
    }
    $this->getJson('/api/v1/admin/shipments/board/stats?filter[tab]=all')->assertStatus(400);
    $this->getJson('/api/v1/admin/shipments/board/issues?filter[from]=2026-11-01')->assertStatus(400);
});

it('cursor-paginates the board', function (): void {
    Support::actingAs(Role::SuperAdmin);
    foreach (range(1, 26) as $i) {
        Kit::shipment(sbOrder($this), ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00']);
    }

    $first = $this->getJson('/api/v1/admin/shipments/board')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.counts.all', 26);
    $next = $first->json('meta.next_cursor');
    expect($next)->toBeString();
    $this->getJson('/api/v1/admin/shipments/board?cursor='.$next)->assertOk()->assertJsonCount(1, 'data');
});

it('masks the customer phone without customers.view_pii', function (): void {
    $order = sbOrder($this);
    Kit::shipment($order, ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00']);
    $role = collect(Role::cases())->first(fn (Role $r): bool => RoleMatrix::allows($r, Permission::ShipmentsView) && ! RoleMatrix::allows($r, Permission::CustomersViewPii));
    expect($role)->not->toBeNull();

    Support::actingAs($role);
    $masked = $this->getJson('/api/v1/admin/shipments/board')->assertOk()->json('data.0.order');
    expect($masked['phone_masked'])->toBeTrue()
        ->and($masked['phone'])->not->toBe($order->shipping_phone)
        ->and($masked['phone'])->toContain('*')
        ->and($masked['customer_name'])->toBe($order->shipping_name);

    auth()->forgetGuards();
    Support::actingAs(Role::SuperAdmin);
    $this->getJson('/api/v1/admin/shipments/board')->assertOk()
        ->assertJsonPath('data.0.order.phone', $order->shipping_phone)
        ->assertJsonPath('data.0.order.phone_masked', false)
        ->assertJsonMissingPath('data.0.order.address');
});

it('shows the tracking card: timeline newest first with sources, payload keys only, label flag', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $s = Kit::shipment(sbOrder($this, 47), ShipmentStatus::Delivered, [
        'booked_at' => '2026-11-05 06:00:00', 'delivered_at' => '2026-11-06 06:00:00',
        'charge' => '70.00', 'cod_fee' => '10.00', 'total_cost' => '80.00', 'cod_collected' => '1000.00', 'delivery_attempts' => 1,
        'tracking_url' => 'https://steadfast.com.bd/t/ABC',
    ]);
    Kit::event($s, ShipmentStatus::Booked, '2026-11-05 06:00:00', 'booking');
    Kit::event($s, ShipmentStatus::InTransit, '2026-11-05 12:00:00', 'poll', 'in_review');
    Kit::event($s, ShipmentStatus::Delivered, '2026-11-06 06:00:00', 'webhook', 'delivered', ['status' => 'delivered', 'recipient_phone' => '01712345678']);

    $json = $this->getJson("/api/v1/admin/shipments/{$s->id}/tracking")->assertOk()
        ->assertJsonPath('charges', ['charge' => '70.00', 'cod_fee' => '10.00', 'return_charge' => null, 'total_cost' => '80.00'])
        ->assertJsonPath('cost.basis', 'recorded')
        ->assertJsonPath('cod_collected', '1000.00')
        ->assertJsonPath('tracking_url', 'https://steadfast.com.bd/t/ABC')
        ->assertJsonPath('can_print_label', true)
        ->assertJsonPath('outcome', null)
        ->assertJsonCount(3, 'events')
        ->assertJsonPath('events.0.status', 'delivered')
        ->assertJsonPath('events.0.source', 'webhook')
        ->assertJsonPath('events.0.payload_keys', ['status', 'recipient_phone'])
        ->assertJsonPath('events.2.source', 'booking')
        ->json();

    // Payload values never leave the server (rule 20).
    expect(json_encode($json))->not->toContain('01712345678');

    $this->getJson('/api/v1/admin/shipments/999999/tracking')->assertNotFound();
});

it('summarises a partial delivery from the recorded return, never guessed', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $other = OrderFixtures::stockedVariant(10, '1750.00', '900.00');
    $order = OrderFixtures::place([$this->variant->id => 1, $other->id => 1]);
    $s = Kit::shipment($order, ShipmentStatus::PartiallyDelivered, ['booked_at' => '2026-11-05 06:00:00', 'delivered_at' => '2026-11-07 06:00:00', 'cod_amount' => '2750.00', 'cod_collected' => '1000.00']);

    $this->getJson("/api/v1/admin/shipments/{$s->id}/tracking")->assertOk()
        ->assertJsonPath('outcome', ['recorded' => false, 'cod_collected' => '1000.00', 'delivered' => [], 'came_back' => []]);

    $line = $order->items()->where('variant_id', $other->id)->firstOrFail();
    $return = OrderReturn::query()->create(['number' => 'RMA-T-1', 'order_id' => $order->id, 'status' => 'requested', 'reason' => 'other']);
    $return->items()->create(['order_item_id' => $line->id, 'quantity' => 1]);

    $outcome = $this->getJson("/api/v1/admin/shipments/{$s->id}/tracking")->assertOk()->json('outcome');
    expect($outcome['recorded'])->toBeTrue()
        ->and($outcome['came_back'])->toHaveCount(1)
        ->and($outcome['came_back'][0])->toMatchArray(['quantity' => 1, 'amount' => '1750.00'])
        ->and($outcome['delivered'])->toHaveCount(1)
        ->and($outcome['delivered'][0])->toMatchArray(['quantity' => 1, 'amount' => '1000.00']);
});

it('reports delivery issues and success by courier × area over the last 30 days', function (): void {
    Support::actingAs(Role::SuperAdmin);
    // Steadfast: Dhaka 2 delivered + 1 returned; outside 1 delivered. Pathao: no outcome → no bar.
    Kit::shipment(sbOrder($this, 47), ShipmentStatus::Delivered, ['booked_at' => '2026-11-01 06:00:00', 'delivered_at' => '2026-11-02 06:00:00']);
    Kit::shipment(sbOrder($this, 47), ShipmentStatus::Delivered, ['booked_at' => '2026-10-20 06:00:00', 'delivered_at' => '2026-10-21 06:00:00']);
    Kit::shipment(Kit::order($this->variant, ['shipping_district_id' => 47, 'shipping_district' => 'Khulna']), ShipmentStatus::Returned, ['booked_at' => '2026-11-01 06:00:00', 'returned_at' => '2026-11-03 06:00:00', 'total_cost' => '120.00']);
    Kit::shipment(sbOrder($this, 8), ShipmentStatus::Delivered, ['booked_at' => '2026-11-01 06:00:00', 'delivered_at' => '2026-11-03 06:00:00']);
    // Outside the 30-day window (2026-10-10 … 2026-11-08 Dhaka).
    Kit::shipment(sbOrder($this, 8), ShipmentStatus::Returned, ['booked_at' => '2026-10-01 06:00:00', 'returned_at' => '2026-10-09 06:00:00', 'total_cost' => '999.00']);
    Kit::shipment(sbOrder($this), ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00'], 'pathao');
    // Failed attempt (went on hold).
    $held = Kit::shipment(sbOrder($this), ShipmentStatus::OnHold, ['booked_at' => '2026-11-06 06:00:00', 'provider_status_raw' => 'Delivery_Failed']);
    Kit::event($held, ShipmentStatus::OnHold, '2026-11-07 06:00:00', 'poll', 'Delivery_Failed');
    // Blocked by an unmapped area.
    Kit::shipment(Kit::order($this->variant, ['shipping_district' => 'Khulna', 'shipping_upazila' => 'Khan Jahan Ali']), ShipmentStatus::Failed, ['consignment_id' => null, 'failure_code' => Shipment::FAILURE_UNMAPPED]);

    $this->getJson('/api/v1/admin/shipments/board/issues')->assertOk()
        ->assertJsonPath('from', '2026-10-10')
        ->assertJsonPath('to', '2026-11-08')
        ->assertJsonPath('returned', ['count' => 1, 'cost' => '120.00', 'districts' => ['Khulna']])
        ->assertJsonPath('failed_attempts', ['count' => 1, 'latest_provider_status' => 'Delivery_Failed'])
        ->assertJsonPath('unmapped', ['count' => 1, 'areas' => ['Khan Jahan Ali, Khulna']])
        ->assertJsonCount(2, 'by_courier')
        ->assertJsonPath('by_courier.0', ['provider' => ['code' => 'steadfast', 'name' => 'Steadfast', 'name_bn' => 'স্টেডফাস্ট'], 'area' => 'dhaka', 'delivered' => 2, 'returned' => 1, 'rate' => '66.7'])
        ->assertJsonPath('by_courier.1.area', 'outside')
        ->assertJsonPath('by_courier.1.rate', '100.0');
});

it('guards every board route with shipments.view', function (): void {
    $s = Kit::shipment(sbOrder($this), ShipmentStatus::Booked, ['booked_at' => '2026-11-07 06:00:00']);

    RoleMatrix::assert($this, Permission::ShipmentsView, fn () => $this->getJson('/api/v1/admin/shipments/board'));
    RoleMatrix::assert($this, Permission::ShipmentsView, fn () => $this->getJson('/api/v1/admin/shipments/board/stats'));
    RoleMatrix::assert($this, Permission::ShipmentsView, fn () => $this->getJson('/api/v1/admin/shipments/board/issues'));
    RoleMatrix::assert($this, Permission::ShipmentsView, fn () => $this->getJson("/api/v1/admin/shipments/{$s->id}/tracking"));
});

it('refuses a guest with 401', function (string $uri): void {
    $this->getJson('/api/v1/admin/'.$uri)->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with(['shipments/board', 'shipments/board/stats', 'shipments/board/issues', 'shipments/1/tracking']);
