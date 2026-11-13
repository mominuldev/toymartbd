<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Jobs\SyncCourierLocations;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RoleMatrix;

/*
 * Admin API for courier location mapping (§3.6). Reads → shipments.view; map, unmap and
 * sync → shipments.manage_locations (rule 21: 403 for every other role). Geography: Dhaka 47
 * (Savar 365, Dhamrai 366), Comilla 1 (Comilla Sadar 11).
 */

const CL = '/api/v1/admin/courier-locations/pathao';

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->pathao = CourierProvider::query()->where('code', 'pathao')->firstOrFail();
    $this->zone = CourierLocation::query()->create([
        'provider_id' => $this->pathao->id, 'external_key' => '1:1538:-',
        'external_city_id' => '1', 'external_city_name' => 'Dhaka',
        'external_zone_id' => '1538', 'external_zone_name' => 'Ashulia Bazar', 'is_active' => true,
    ]);
    $this->area = CourierLocation::query()->create([
        'provider_id' => $this->pathao->id, 'external_key' => '1:1538:5001',
        'external_city_id' => '1', 'external_city_name' => 'Dhaka',
        'external_zone_id' => '1538', 'external_zone_name' => 'Ashulia Bazar',
        'external_area_id' => '5001', 'external_area_name' => 'Baipail', 'is_active' => true,
    ]);
});

function actingAsRole(Role $role): User
{
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user);

    return $user;
}

it('guards the read endpoints with shipments.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL.'/locations'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL.'/needs-mapping'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL.'/places?q=Sav'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL.'/suggestions?district_id=47&upazila_id=365'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL.'/resolve?district_id=47&upazila_id=365'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CL."/locations/{$this->zone->id}/suggestions"));
})->with(RoleMatrix::dataset(Permission::ShipmentsView));

it('guards map, unmap and sync with shipments.manage_locations', function (Role $role, bool $allowed): void {
    Queue::fake();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(CL."/locations/{$this->zone->id}/mapping", ['district_id' => 47, 'upazila_id' => 365]));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson(CL."/locations/{$this->zone->id}/mapping"));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(CL.'/sync'), 202);
})->with(RoleMatrix::dataset(Permission::ShipmentsManageLocations));

it('maps and unmaps a zone, recording who and when', function (): void {
    $user = actingAsRole(Role::Manager);

    $this->putJson(CL."/locations/{$this->zone->id}/mapping", ['district_id' => 47, 'upazila_id' => 365])
        ->assertOk()
        ->assertJsonPath('is_mapped', true)
        ->assertJsonPath('district.id', 47)
        ->assertJsonPath('upazila.name', 'Savar')
        ->assertJsonPath('level', 'zone');

    expect($this->zone->refresh()->mapped_by)->toBe($user->id);

    $this->getJson(CL.'/resolve?district_id=47&upazila_id=365')
        ->assertOk()
        ->assertJsonPath('status', 'resolved')
        ->assertJsonPath('ref.zone_id', '1538')
        ->assertJsonPath('ref.area_id', null);

    $this->deleteJson(CL."/locations/{$this->zone->id}/mapping")->assertOk()->assertJsonPath('is_mapped', false)->assertJsonPath('district', null);
    $this->getJson(CL.'/resolve?district_id=47&upazila_id=365')->assertJsonPath('status', 'unmapped')->assertJsonPath('can_book', false);
});

it('refuses an upazila outside the chosen district and an inactive row', function (): void {
    actingAsRole(Role::Manager);

    $this->putJson(CL."/locations/{$this->zone->id}/mapping", ['district_id' => 1, 'upazila_id' => 365])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'upazila_id');

    $this->zone->update(['is_active' => false]);
    $this->putJson(CL."/locations/{$this->zone->id}/mapping", ['district_id' => 47, 'upazila_id' => 365])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'location');
});

it('lists places that waiting orders need, most first, until they are mapped', function (): void {
    actingAsRole(Role::OrderOperator);
    Order::factory()->count(3)->create(['status' => OrderStatus::Confirmed, 'shipping_district_id' => 47, 'shipping_upazila_id' => 365]);
    Order::factory()->create(['status' => OrderStatus::Pending, 'shipping_district_id' => 1, 'shipping_upazila_id' => 11]);
    Order::factory()->create(['status' => OrderStatus::Shipped, 'shipping_district_id' => 47, 'shipping_upazila_id' => 366]);

    $this->getJson(CL.'/needs-mapping')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.upazila', 'Savar')
        ->assertJsonPath('data.0.waiting_orders', 3)
        ->assertJsonPath('data.1.upazila', 'Comilla Sadar');

    $this->zone->forceFill(['district_id' => 47, 'upazila_id' => 365, 'is_mapped' => true])->save();

    $this->getJson(CL.'/needs-mapping')->assertJsonCount(1, 'data')->assertJsonPath('data.0.district', 'Comilla');
});

it('returns suggestions labelled as such, and writes nothing', function (): void {
    actingAsRole(Role::Manager);
    CourierLocation::query()->create([
        'provider_id' => $this->pathao->id, 'external_key' => '1:99001:-',
        'external_city_id' => '1', 'external_city_name' => 'Dhaka',
        'external_zone_id' => '99001', 'external_zone_name' => 'Savar', 'is_active' => true,
    ]);

    $this->getJson(CL.'/suggestions?district_id=47&upazila_id=365')
        ->assertOk()
        ->assertJsonPath('data.0.reason', 'name_similarity')
        ->assertJsonPath('data.0.location.zone_name', 'Savar')
        ->assertJsonPath('data.0.location.is_mapped', false);

    $this->getJson(CL."/locations/{$this->area->id}/suggestions")->assertOk();

    expect(CourierLocation::query()->where('is_mapped', true)->count())->toBe(0);
});

it('filters the courier rows through an allowlist', function (): void {
    actingAsRole(Role::Manager);

    $this->getJson(CL.'/locations?filter[city]=1&filter[level]=area')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.area_name', 'Baipail');
    $this->getJson(CL.'/locations?filter[search]=ashulia&filter[status]=unmapped')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(CL.'/locations?filter[status]=bogus')->assertStatus(400);
    $this->getJson(CL.'/locations?filter[district]=47')->assertStatus(400);
});

it('finds places in English and Bangla for the picker', function (): void {
    actingAsRole(Role::Manager);

    $this->getJson(CL.'/places?q=Savar')->assertOk()->assertJsonPath('data.0.upazila_id', 365)->assertJsonPath('data.0.district', 'Dhaka');
    $this->getJson(CL.'/places?q='.urlencode('সাভার'))->assertOk()->assertJsonPath('data.0.upazila_id', 365);
});

it('shows the overview with cities and the last sync', function (): void {
    actingAsRole(Role::Manager);
    Illuminate\Support\Facades\Cache::forever(SyncCourierLocations::lastReportKey('pathao'), ['cities' => 64, 'zones' => 1323, 'areas' => 38520, 'created' => 0, 'deactivated' => 0, 'mapped_inactive' => 0, 'finished_at' => '2026-11-13T12:00:00+00:00']);

    $this->getJson(CL)
        ->assertOk()
        ->assertJsonPath('provider.code', 'pathao')
        ->assertJsonPath('zones', 1)
        ->assertJsonPath('areas', 1)
        ->assertJsonPath('cities.0.name', 'Dhaka')
        ->assertJsonPath('last_sync.zones', 1323);
});

it('404s a provider without a location tree, and a row of another provider', function (): void {
    actingAsRole(Role::Manager);

    $this->getJson('/api/v1/admin/courier-locations/steadfast')->assertNotFound();
    $this->getJson('/api/v1/admin/courier-locations/nope')->assertNotFound();

    $other = CourierProvider::query()->create(['code' => 'mockmap', 'name' => 'Mock', 'requires_location_mapping' => true]);
    $this->putJson("/api/v1/admin/courier-locations/mockmap/locations/{$this->zone->id}/mapping", ['district_id' => 47])->assertNotFound();
    expect($other->id)->not->toBe($this->pathao->id);
});

it('queues a sync on the courier queue', function (): void {
    Queue::fake();
    actingAsRole(Role::Manager);

    $this->postJson(CL.'/sync')->assertStatus(202);

    Queue::assertPushedOn('courier', SyncCourierLocations::class, static fn (SyncCourierLocations $job): bool => $job->providerCode === 'pathao');
});
