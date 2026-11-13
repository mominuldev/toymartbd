<?php

declare(strict_types=1);

use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;
use Tests\Support\Xlsx;

/*
 * P6 — courier performance per provider and district over Dhaka dates. The expected figures
 * below are computed by hand from the fixture table, not by the code under test.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reportShipment(string $provider, int $districtId, array $attributes): Shipment
{
    static $n = 0;
    $order = Order::factory()->status(OrderStatus::Shipped)->create(['shipping_district_id' => $districtId]);

    return Shipment::query()->create([
        'order_id' => $order->id,
        'provider_id' => CourierProvider::query()->where('code', $provider)->value('id'),
        'number' => 'SHP-26-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT),
        'declared_value' => '0.00',
        ...$attributes,
    ]);
}

/** The fixture table. Times are UTC; Dhaka = UTC+6. Period: 1–7 Nov 2026 (Dhaka). */
function reportFixtures(): array
{
    [$dhaka, $ctg] = District::query()->orderBy('id')->limit(2)->pluck('id')->all();
    $d = static fn (string $utc): string => $utc;

    // Steadfast
    reportShipment('steadfast', $dhaka, ['status' => ShipmentStatus::Delivered, 'booked_at' => $d('2026-11-01 04:00:00'), 'delivered_at' => $d('2026-11-03 04:00:00'), 'charge' => '70.00', 'cod_amount' => '1000.00', 'cod_collected' => '1000.00']); // S1: 2 days
    reportShipment('steadfast', $ctg, ['status' => ShipmentStatus::Delivered, 'booked_at' => $d('2026-11-02 00:00:00'), 'delivered_at' => $d('2026-11-03 00:00:00'), 'charge' => '100.00', 'cod_amount' => '500.00']); // S2: 1 day, collected unknown → its COD
    reportShipment('steadfast', $ctg, ['status' => ShipmentStatus::Returned, 'booked_at' => $d('2026-11-03 05:00:00'), 'returned_at' => $d('2026-11-05 05:00:00'), 'charge' => '130.00', 'cod_amount' => '800.00']); // S3
    reportShipment('steadfast', $dhaka, ['status' => ShipmentStatus::InTransit, 'booked_at' => $d('2026-11-06 05:00:00'), 'charge' => '70.00', 'cod_amount' => '1200.00']); // S4
    reportShipment('steadfast', $dhaka, ['status' => ShipmentStatus::Booked, 'booked_at' => $d('2026-10-31 18:30:00'), 'cod_amount' => '300.00']); // S5: Dhaka 1 Nov 00:30 → in
    reportShipment('steadfast', $dhaka, ['status' => ShipmentStatus::Booked, 'booked_at' => $d('2026-11-07 18:30:00'), 'charge' => '999.00', 'cod_amount' => '9999.00']); // S6: Dhaka 8 Nov → out
    reportShipment('steadfast', $dhaka, ['status' => ShipmentStatus::Failed, 'cod_amount' => '5000.00']); // S7: never reached a courier
    // Pathao
    reportShipment('pathao', $dhaka, ['status' => ShipmentStatus::PartiallyDelivered, 'booked_at' => $d('2026-11-04 03:00:00'), 'delivered_at' => $d('2026-11-07 03:00:00'), 'charge' => '60.00', 'cod_amount' => '600.00', 'cod_collected' => '250.00']); // P1: 3 days
    reportShipment('pathao', $ctg, ['status' => ShipmentStatus::Cancelled, 'booked_at' => $d('2026-11-05 03:00:00'), 'cod_amount' => '400.00']); // P2

    return [$dhaka, $ctg];
}

it('matches a hand computation per provider, per district and in total', function (): void {
    Support::actingAs(Role::Accountant);
    [$dhaka, $ctg] = reportFixtures();

    $json = $this->getJson('/api/v1/admin/reports/couriers?filter[from]=2026-11-01&filter[to]=2026-11-07')->assertOk()->json();

    $metrics = static fn (array $row): array => array_intersect_key($row, array_flip(['booked', 'delivered', 'partially_delivered', 'returned', 'cancelled', 'in_flight', 'delivery_rate', 'return_rate', 'average_days_to_deliver', 'average_charge', 'cod_expected', 'cod_collected', 'cod_outstanding']));

    expect($json['from'])->toBe('2026-11-01')->and($json['to'])->toBe('2026-11-07')
        ->and($metrics($json['totals']))->toBe([
            'booked' => 7, 'delivered' => 3, 'partially_delivered' => 1, 'returned' => 1, 'cancelled' => 1, 'in_flight' => 2,
            'delivery_rate' => '75.00', 'return_rate' => '25.00', 'average_days_to_deliver' => '2.0', 'average_charge' => '86.00',
            'cod_expected' => '4400.00', 'cod_collected' => '1750.00', 'cod_outstanding' => '1500.00',
        ])
        ->and(array_column(array_column($json['providers'], 'provider'), 'code'))->toBe(['steadfast', 'pathao'])
        ->and($metrics($json['providers'][0]))->toBe([
            'booked' => 5, 'delivered' => 2, 'partially_delivered' => 0, 'returned' => 1, 'cancelled' => 0, 'in_flight' => 2,
            'delivery_rate' => '66.67', 'return_rate' => '33.33', 'average_days_to_deliver' => '1.5', 'average_charge' => '92.50',
            'cod_expected' => '3800.00', 'cod_collected' => '1500.00', 'cod_outstanding' => '1500.00',
        ])
        ->and($json['providers'][1]['provider'])->toMatchArray(['code' => 'pathao', 'name_bn' => 'পাঠাও'])
        ->and($metrics($json['providers'][1]))->toBe([
            'booked' => 2, 'delivered' => 1, 'partially_delivered' => 1, 'returned' => 0, 'cancelled' => 1, 'in_flight' => 0,
            'delivery_rate' => '100.00', 'return_rate' => '0.00', 'average_days_to_deliver' => '3.0', 'average_charge' => '60.00',
            'cod_expected' => '600.00', 'cod_collected' => '250.00', 'cod_outstanding' => '0.00',
        ])
        ->and(array_column(array_column($json['districts'], 'district'), 'id'))->toBe([$dhaka, $ctg])
        ->and($metrics($json['districts'][0]))->toBe([
            'booked' => 4, 'delivered' => 2, 'partially_delivered' => 1, 'returned' => 0, 'cancelled' => 0, 'in_flight' => 2,
            'delivery_rate' => '100.00', 'return_rate' => '0.00', 'average_days_to_deliver' => '2.5', 'average_charge' => '66.67',
            'cod_expected' => '3100.00', 'cod_collected' => '1250.00', 'cod_outstanding' => '1500.00',
        ])
        ->and($metrics($json['districts'][1]))->toBe([
            'booked' => 3, 'delivered' => 1, 'partially_delivered' => 0, 'returned' => 1, 'cancelled' => 1, 'in_flight' => 0,
            'delivery_rate' => '50.00', 'return_rate' => '50.00', 'average_days_to_deliver' => '1.0', 'average_charge' => '115.00',
            'cod_expected' => '1300.00', 'cod_collected' => '500.00', 'cod_outstanding' => '0.00',
        ]);
});

it('filters by provider and refuses unlisted filters and unknown providers', function (): void {
    Support::actingAs(Role::Manager);
    reportFixtures();

    $json = $this->getJson('/api/v1/admin/reports/couriers?filter[from]=2026-11-01&filter[to]=2026-11-07&filter[provider]=pathao')->assertOk()->json();
    expect($json['provider'])->toBe('pathao')->and($json['totals']['booked'])->toBe(2)->and($json['providers'])->toHaveCount(1);

    $this->getJson('/api/v1/admin/reports/couriers?filter[status]=delivered')->assertStatus(400);
    $this->getJson('/api/v1/admin/reports/couriers?sort=booked')->assertStatus(400);
    $this->getJson('/api/v1/admin/reports/couriers?filter[provider]=dhl')->assertStatus(400);
    $this->getJson('/api/v1/admin/reports/couriers?filter[from]=2026-13-01')->assertStatus(400);
});

it('exports the same figures as CSV', function (): void {
    Support::actingAs(Role::Accountant);
    reportFixtures();

    $response = $this->get('/api/v1/admin/reports/couriers/export?filter[from]=2026-11-01&filter[to]=2026-11-07')->assertOk();
    $csv = file_get_contents($response->getFile()->getPathname());

    expect($csv)->toContain('scope,code,name,name_bn,booked,delivered')
        ->and($csv)->toContain('total,,"All couriers",,7,3,1,1,1,2,75.00,25.00,2.0,86.00,4400.00,1750.00,1500.00')
        ->and($csv)->toContain('provider,steadfast,Steadfast,স্টেডফাস্ট,5,2,0,1,0,2,66.67,33.33,1.5,92.50,3800.00,1500.00,1500.00');
});

it('keeps the courier CSV byte-for-byte as before XLSX', function (): void {
    Support::actingAs(Role::Accountant);
    [$dhaka, $ctg] = reportFixtures();
    $names = District::query()->whereIn('id', [$dhaka, $ctg])->get()->keyBy('id');

    $response = $this->get('/api/v1/admin/reports/couriers/export?filter[from]=2026-11-01&filter[to]=2026-11-07&format=csv')->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename=courier-performance-2026-11-01-to-2026-11-07.csv');

    expect(file_get_contents($response->getFile()->getPathname()))->toBe(
        "\u{FEFF}scope,code,name,name_bn,booked,delivered,partially_delivered,returned,cancelled,in_flight,delivery_rate,return_rate,average_days_to_deliver,average_charge,cod_expected,cod_collected,cod_outstanding\n"
        ."total,,\"All couriers\",,7,3,1,1,1,2,75.00,25.00,2.0,86.00,4400.00,1750.00,1500.00\n"
        ."provider,steadfast,Steadfast,স্টেডফাস্ট,5,2,0,1,0,2,66.67,33.33,1.5,92.50,3800.00,1500.00,1500.00\n"
        ."provider,pathao,Pathao,পাঠাও,2,1,1,0,1,0,100.00,0.00,3.0,60.00,600.00,250.00,0.00\n"
        ."district,{$dhaka},{$names[$dhaka]->name},{$names[$dhaka]->name_bn},4,2,1,0,0,2,100.00,0.00,2.5,66.67,3100.00,1250.00,1500.00\n"
        ."district,{$ctg},{$names[$ctg]->name},{$names[$ctg]->name_bn},3,1,0,1,1,0,50.00,50.00,1.0,115.00,1300.00,500.00,0.00\n",
    );
});

it('exports the same figures as XLSX, with Bangla labels and guarded text', function (): void {
    Support::actingAs(Role::Manager);
    reportFixtures();
    CourierProvider::query()->where('code', 'pathao')->update(['name' => '=cmd|calc']);
    $range = 'filter[from]=2026-11-01&filter[to]=2026-11-07';

    $json = $this->getJson("/api/v1/admin/reports/couriers?{$range}")->assertOk()->json();
    $response = $this->get("/api/v1/admin/reports/couriers/export?{$range}&format=xlsx&locale=bn");
    $response->assertHeader('Content-Disposition', 'attachment; filename="courier-performance-2026-11-01-to-2026-11-07.xlsx"');
    $sheet = Xlsx::single($response);

    expect($sheet['name'])->toBe('কুরিয়ার পারফরম্যান্স')
        ->and($sheet['rows'])->toHaveCount(1 + count($json['providers']) + count($json['districts']));
    $expected = [$json['totals'], ...$json['providers'], ...$json['districts']];
    foreach ($expected as $i => $figures) {
        foreach (['booked', 'delivered', 'partially_delivered', 'returned', 'cancelled', 'in_flight'] as $count) {
            expect($sheet['rows'][$i][$count])->toBe($figures[$count]);
        }
        foreach (['delivery_rate', 'return_rate', 'average_days_to_deliver', 'average_charge', 'cod_expected', 'cod_collected', 'cod_outstanding'] as $decimal) {
            expect($sheet['rows'][$i][$decimal])->toEqual(Xlsx::number($figures[$decimal]));
        }
    }
    $pathao = collect($sheet['rows'])->firstWhere('code', 'pathao');
    expect($sheet['rows'][0]['name'])->toBe((string) __('reports.couriers.all', [], 'bn'))
        ->and($pathao['name'])->toBe("'=cmd|calc")
        ->and($pathao['name_bn'])->toBe('পাঠাও')
        // A district id is a code, not a quantity: it stays text.
        ->and(collect($sheet['rows'])->firstWhere('scope', 'district')['code'])->toBe((string) $json['districts'][0]['district']['id']);
});

describe('role matrix', function (): void {
    it('guards the report with reports.view + shipments.view', function (Role $role): void {
        $allowed = RoleMatrix::allows($role, Permission::ReportsView) && RoleMatrix::allows($role, Permission::ShipmentsView);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/reports/couriers'));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards the export with reports.export too', function (Role $role): void {
        $allowed = RoleMatrix::allows($role, Permission::ReportsView) && RoleMatrix::allows($role, Permission::ShipmentsView) && RoleMatrix::allows($role, Permission::ReportsExport);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get('/api/v1/admin/reports/couriers/export', ['Accept' => 'application/json']));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards the XLSX export the same way, and refuses an unknown format or locale with 400', function (Role $role): void {
        $allowed = RoleMatrix::allows($role, Permission::ReportsView) && RoleMatrix::allows($role, Permission::ShipmentsView) && RoleMatrix::allows($role, Permission::ReportsExport);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get('/api/v1/admin/reports/couriers/export?format=xlsx', ['Accept' => 'application/json']));
        if ($allowed) {
            $this->get('/api/v1/admin/reports/couriers/export?format=pdf', ['Accept' => 'application/json'])->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
            $this->get('/api/v1/admin/reports/couriers/export?locale=fr', ['Accept' => 'application/json'])->assertStatus(400);
        }
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('refuses a guest with 401', function (): void {
        $this->getJson('/api/v1/admin/reports/couriers')->assertUnauthorized();
    });
});
