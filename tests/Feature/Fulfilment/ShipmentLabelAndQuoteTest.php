<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * P6 — shipping labels (mPDF, Bangla shaped, gated by customers.view_pii) and the booking
 * panel's server-side charge preview (rule 1: the browser computes no money).
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

function labelShipment(array $overrides = []): Shipment
{
    $order = Order::factory()->status(OrderStatus::Shipped)->create([
        'shipping_name' => 'রাহেলা খাতুন',
        'shipping_address' => 'বাড়ি ১২, রোড ৫, ব্লক সি',
        'shipping_landmark' => 'জামে মসজিদের পাশে, ৩য় তলা',
    ]);

    return Shipment::query()->create([
        'order_id' => $order->id,
        'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'status' => ShipmentStatus::Booked,
        'consignment_id' => '1234567',
        'tracking_code' => '15BAEB8A',
        'weight_grams' => 920,
        'item_count' => 2,
        'cod_amount' => '2310.00',
        'declared_value' => '2810.00',
        'booked_at' => now(),
        ...$overrides,
    ]);
}

it('renders a label with the consignment barcode and a shaped Bangla address', function (): void {
    Support::actingAs(Role::OrderOperator);
    $shipment = labelShipment();

    $response = $this->get("/api/v1/admin/shipments/{$shipment->id}/label?locale=bn")->assertOk();
    $pdf = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and($pdf)->toContain('HindSiliguri')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuses a label before the consignment id exists (bulk-submitted, rule 24d)', function (): void {
    Support::actingAs(Role::OrderOperator);
    $submitted = labelShipment(['status' => ShipmentStatus::Submitted, 'consignment_id' => null, 'tracking_code' => null, 'booked_at' => null]);

    $this->getJson("/api/v1/admin/shipments/{$submitted->id}/label")->assertStatus(409);
    $this->postJson('/api/v1/admin/shipments/labels', ['shipment_ids' => [$submitted->id]])->assertStatus(409);
});

it('prints bulk labels in request order and lists the skipped shipments', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = labelShipment();
    $b = labelShipment();
    $submitted = labelShipment(['status' => ShipmentStatus::Submitted, 'consignment_id' => null]);

    $response = $this->postJson('/api/v1/admin/shipments/labels', ['shipment_ids' => [$b->id, $submitted->id, 999999, $a->id]])->assertOk();

    expect($response->headers->get('X-Shipments-Skipped'))->toBe($submitted->id.',999999')
        ->and(substr_count((string) $response->getContent(), '/Type /Page'))->toBeGreaterThanOrEqual(2);
});

it('guards labels with shipments.view plus customers.view_pii', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::ShipmentsView) && RoleMatrix::allows($role, Permission::CustomersViewPii);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get('/api/v1/admin/shipments/'.labelShipment()->id.'/label', ['Accept' => 'application/json']));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/shipments/labels', ['shipment_ids' => [labelShipment()->id]]));
})->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

function quotableOrder(int $districtId, ?int $upazilaId, int $grams, string $due = '2310.00'): Order
{
    $variant = ProductVariant::factory()->create(['weight_grams' => $grams]);
    $order = Order::factory()->status(OrderStatus::ReadyToShip)->create([
        'shipping_district_id' => $districtId, 'shipping_upazila_id' => $upazilaId,
        'grand_total' => '2810.00', 'paid_total' => '500.00', 'due_total' => $due,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => 2]);

    return $order;
}

it('quotes every courier from the stored rate card: weight step, COD fee, refusal cost', function (): void {
    Support::actingAs(Role::OrderOperator);
    // Chattogram (8), outside Dhaka; 2 × 460 g = 920 g → under both allowances.
    $order = quotableOrder(8, null, 460);

    $json = $this->postJson('/api/v1/admin/shipments/quote', ['orders' => [['id' => $order->id]]])->assertOk()->json();

    $providers = collect($json['providers'])->keyBy('code');
    expect($providers['steadfast']['trust'])->toBe('not_connected')
        ->and($providers['steadfast']['blocked_reason'])->toBe('not_connected')
        ->and($providers['pathao']['blocked_reason'])->toBe('inactive');

    $row = $json['orders'][0];
    expect($row)->toMatchArray(['found' => true, 'zone' => 'outside_dhaka', 'item_count' => 2, 'weight_grams' => 920, 'cod_amount' => '2310.00'])
        ->and($row['district']['name_bn'])->not->toBeEmpty();
    $quotes = collect($row['quotes'])->keyBy('provider_id');
    $steadfast = $quotes[$providers['steadfast']['id']];
    // Seeded Steadfast: outside ৳130, +৳20/kg over 1 kg, COD 1%, return ৳0.
    expect($steadfast['location_status'])->toBe('not_required')
        ->and($steadfast['estimate'])->toMatchArray([
            'delivery_charge' => '130.00', 'weight_surcharge' => '0.00', 'cod_fee' => '23.10',
            'total_if_delivered' => '153.10', 'cost_if_refused' => '130.00',
        ]);
    // Pathao needs a mapped zone; none is mapped, so it is blocked — never guessed (rule 32).
    expect($quotes[$providers['pathao']['id']]['location_status'])->toBe('unmapped')
        ->and($quotes[$providers['pathao']['id']]['blocked_reason'])->toBe('unmapped_location');

    // A weighed parcel overrides the variant weight: 2,300 g → 2 started kg over 1 kg.
    $json = $this->postJson('/api/v1/admin/shipments/quote', ['orders' => [['id' => $order->id, 'weight_grams' => 2300]]])->json();
    $steadfast = collect($json['orders'][0]['quotes'])->firstWhere('provider_id', $providers['steadfast']['id']);
    expect($json['orders'][0]['variant_weight_grams'])->toBe(920)
        ->and($steadfast['estimate']['weight_surcharge'])->toBe('40.00')
        ->and($json['totals'][0])->toMatchArray(['parcels' => 1, 'cod_amount' => '2310.00']);

    // Read-only: a preview moves no stock and writes no ledger row (the §7 invariants are untouched).
    expect(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('financial_transactions')->count())->toBe(0)
        ->and(Shipment::query()->count())->toBe(0);
});

it('offers the mapped Pathao zones and flags several as ambiguous', function (): void {
    Support::actingAs(Role::OrderOperator);
    $pathao = CourierProvider::query()->where('code', 'pathao')->firstOrFail();
    $order = quotableOrder(8, 65, 400);
    $map = fn (string $zone) => CourierLocation::query()->create([
        'provider_id' => $pathao->id, 'external_key' => "2:{$zone}:-", 'external_city_id' => '2', 'external_city_name' => 'Chittagong',
        'external_zone_id' => $zone, 'external_zone_name' => 'Zone '.$zone, 'upazila_id' => 65, 'district_id' => 8,
        'is_mapped' => true, 'mapped_at' => now(), 'is_active' => true,
    ]);
    $map('11');

    $quote = fn () => collect($this->postJson('/api/v1/admin/shipments/quote', ['orders' => [['id' => $order->id]]])->json('orders.0.quotes'))->firstWhere('provider_id', $pathao->id);
    expect($quote()['location_status'])->toBe('mapped')->and($quote()['location_options'])->toHaveCount(1);
    $map('12');
    expect($quote()['location_status'])->toBe('ambiguous')->and($quote()['blocked_reason'])->toBeNull();
});

it('reports an unknown order without failing the batch, and guards the quote like booking', function (): void {
    Support::actingAs(Role::OrderOperator);
    $this->postJson('/api/v1/admin/shipments/quote', ['orders' => [['id' => 999999]]])->assertOk()->assertJsonPath('orders.0.found', false);
    $this->postJson('/api/v1/admin/shipments/quote', ['orders' => []])->assertStatus(422);
});

it('guards the quote with orders.update plus shipments.book', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::OrdersUpdate) && RoleMatrix::allows($role, Permission::ShipmentsBook);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/shipments/quote', ['orders' => [['id' => 1]]]));
})->with(array_map(static fn (Role $r): array => [$r], Role::cases()));
