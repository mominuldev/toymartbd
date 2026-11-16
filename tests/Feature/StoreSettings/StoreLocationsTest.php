<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Branches & warehouses (Figma B · 38). Stock locations with a type, an
 * address per rule 31 (district → upazila → area + line + landmark; the division is the
 * district's) and a facts line. Never deleted: switched off, and only when nothing depends
 * on them (not the default, no stock held, reserved or expected).
 */

const LOCATIONS_URL = '/api/v1/admin/settings/locations';

// These tests add branches, so the store runs with multiple branches (single mode: BranchModeTest).
beforeEach(fn () => Setting::put(BranchMode::KEY, true, SettingGroup::Store));

/** @return array{District, Upazila, District} */
function locationPlaces(): array
{
    $division = Division::query()->firstOrCreate(['name' => 'Chattogram'], ['name_bn' => 'চট্টগ্রাম']);
    $ctg = District::query()->create(['division_id' => $division->id, 'name' => 'Chattogram', 'name_bn' => 'চট্টগ্রাম']);
    $double = Upazila::query()->create(['district_id' => $ctg->id, 'name' => 'Double Mooring', 'name_bn' => 'ডবলমুরিং']);
    $other = District::query()->create(['division_id' => $division->id, 'name' => 'Cumilla', 'name_bn' => 'কুমিল্লা']);

    return [$ctg, $double, $other];
}

/** @return array<string, mixed> */
function locationBody(District $district, array $overrides = []): array
{
    return [
        'code' => 'CTG',
        'name' => 'Chattogram · Agrabad',
        'name_bn' => 'চট্টগ্রাম · আগ্রাবাদ',
        'type' => 'retail',
        'district_id' => $district->id,
        'upazila_id' => null,
        'area' => 'Agrabad',
        'address_line' => 'Shop 12, Agrabad Commercial Area',
        'landmark' => 'Opposite Jamboree Park',
        'notes' => '1 register · open 10 am–9 pm',
        'is_active' => true,
        ...$overrides,
    ];
}

it('lists every location, the default first, with what blocks switching it off', function (): void {
    Support::actingAs(Role::Manager);
    $variant = ProductVariant::factory()->create();
    StockLevel::query()->create(['variant_id' => $variant->id, 'location_id' => StockLocation::defaultId(), 'on_hand' => 3]);
    StockLocation::query()->create(['code' => 'old', 'name' => 'Old shop', 'is_active' => false]);

    $this->getJson(LOCATIONS_URL)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.0.holds_stock', true)
        ->assertJsonPath('data.0.type', null)
        ->assertJsonPath('data.1.code', 'old')
        ->assertJsonPath('data.1.is_active', false)
        ->assertJsonPath('data.1.holds_stock', false);
});

it('adds a branch with a rule-31 address, the division taken from the district', function (): void {
    Support::actingAs(Role::Manager);
    [$ctg, $double] = locationPlaces();

    $response = $this->postJson(LOCATIONS_URL, locationBody($ctg, ['upazila_id' => $double->id]))->assertCreated()
        ->assertJsonPath('code', 'ctg')
        ->assertJsonPath('type', 'retail')
        ->assertJsonPath('division.name', 'Chattogram')
        ->assertJsonPath('district.name_bn', 'চট্টগ্রাম')
        ->assertJsonPath('upazila.name', 'Double Mooring')
        ->assertJsonPath('landmark', 'Opposite Jamboree Park')
        ->assertJsonPath('is_default', false);

    $location = StockLocation::query()->findOrFail($response->json('id'));
    expect($location->type)->toBe(StockLocationType::Retail)
        ->and($location->division_id)->toBe($ctg->division_id)
        ->and($location->is_default)->toBeFalse();
});

it('validates the branch', function (array $overrides, string $field): void {
    Support::actingAs(Role::Manager);
    [$ctg, , $other] = locationPlaces();
    $wrongUpazila = Upazila::query()->create(['district_id' => $other->id, 'name' => 'Laksam', 'name_bn' => 'লাকসাম']);
    $overrides = array_map(static fn (mixed $v): mixed => $v === 'WRONG_UPAZILA' ? $wrongUpazila->id : $v, $overrides);

    $this->postJson(LOCATIONS_URL, locationBody($ctg, $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'unknown type' => [['type' => 'franchise'], 'type'],
    'no district' => [['district_id' => null], 'district_id'],
    'no address line' => [['address_line' => ''], 'address_line'],
    'upazila of another district' => [['upazila_id' => 'WRONG_UPAZILA'], 'upazila_id'],
    'taken code' => [['code' => 'main'], 'code'],
    'code with spaces' => [['code' => 'c t g'], 'code'],
]);

it('edits a branch but never its code', function (): void {
    Support::actingAs(Role::Manager);
    [$ctg] = locationPlaces();
    $id = $this->postJson(LOCATIONS_URL, locationBody($ctg))->assertCreated()->json('id');

    $this->putJson(LOCATIONS_URL."/{$id}", locationBody($ctg, ['code' => 'zzz', 'name' => 'Chattogram · GEC', 'type' => 'pickup', 'landmark' => null]))->assertOk()
        ->assertJsonPath('code', 'ctg')
        ->assertJsonPath('name', 'Chattogram · GEC')
        ->assertJsonPath('type', 'pickup')
        ->assertJsonPath('landmark', null);
});

it('switches an empty branch off and on again, never deleting it', function (): void {
    Support::actingAs(Role::Manager);
    [$ctg] = locationPlaces();
    $id = $this->postJson(LOCATIONS_URL, locationBody($ctg))->assertCreated()->json('id');

    $this->putJson(LOCATIONS_URL."/{$id}", locationBody($ctg, ['is_active' => false]))->assertOk()->assertJsonPath('is_active', false);
    expect(StockLocation::query()->whereKey($id)->exists())->toBeTrue();
    $this->putJson(LOCATIONS_URL."/{$id}", locationBody($ctg, ['is_active' => true]))->assertOk()->assertJsonPath('is_active', true);
    $this->deleteJson(LOCATIONS_URL."/{$id}")->assertStatus(405);
});

it('refuses to switch off the default location or one holding stock (409)', function (): void {
    Support::actingAs(Role::Manager);
    [$ctg] = locationPlaces();
    $default = StockLocation::defaultId();

    $this->putJson(LOCATIONS_URL."/{$default}", locationBody($ctg, ['is_active' => false]))
        ->assertConflict()->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'conflict'));

    $id = $this->postJson(LOCATIONS_URL, locationBody($ctg))->assertCreated()->json('id');
    StockLevel::query()->create(['variant_id' => ProductVariant::factory()->create()->id, 'location_id' => $id, 'reserved' => 1]);
    $this->putJson(LOCATIONS_URL."/{$id}", locationBody($ctg, ['is_active' => false]))->assertConflict();
    expect(StockLocation::query()->findOrFail($id)->is_active)->toBeTrue();
});

it('lists districts with their division and upazilas for the address picker', function (): void {
    Support::actingAs(Role::Manager);
    [$ctg] = locationPlaces();

    $this->getJson(LOCATIONS_URL.'/districts')->assertOk()
        ->assertJsonPath('data.0.name', 'Chattogram')
        ->assertJsonPath('data.0.division.name', 'Chattogram')
        ->assertJsonPath('data.0.upazilas.0.name', 'Double Mooring');
});

describe('role matrix', function (): void {
    it('guards the reads with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(LOCATIONS_URL));
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(LOCATIONS_URL.'/districts'));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards the writes with settings.update', function (Role $role, bool $allowed): void {
        [$ctg] = locationPlaces();
        $default = StockLocation::defaultId();
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(LOCATIONS_URL, locationBody($ctg, ['code' => 'b-'.str_replace('_', '-', $role->value)])), 201);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(LOCATIONS_URL."/{$default}", locationBody($ctg)));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));
});
