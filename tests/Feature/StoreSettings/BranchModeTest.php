<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Branches & warehouses › Branch setup (owner request 2026-11-16): a single
 * shop or multiple branches. Single mode ⇔ at most one active location: going single is
 * refused while other branches are active, and adding / switching on a branch is refused
 * while single.
 */

const BRANCH_MODE_URL = '/api/v1/admin/settings/branch-mode';
const BRANCH_MODE_LOCATIONS_URL = '/api/v1/admin/settings/locations';

function branchModeBranch(string $code, bool $active = true): StockLocation
{
    return StockLocation::query()->create(['code' => $code, 'name' => "Branch {$code}", 'name_bn' => "শাখা {$code}", 'is_active' => $active]);
}

/** @return array<string, mixed> */
function branchModeLocationBody(array $overrides = []): array
{
    $division = App\Domain\Customer\Models\Division::query()->firstOrCreate(['name' => 'Sylhet'], ['name_bn' => 'সিলেট']);
    $district = App\Domain\Customer\Models\District::query()->firstOrCreate(['name' => 'Sylhet'], ['division_id' => $division->id, 'name_bn' => 'সিলেট']);

    return [
        'code' => 'syl', 'name' => 'Sylhet · Zindabazar', 'name_bn' => 'সিলেট · জিন্দাবাজার', 'type' => 'retail',
        'district_id' => $district->id, 'upazila_id' => null, 'area' => 'Zindabazar', 'address_line' => 'Shop 4, Al-Hamra',
        'landmark' => null, 'notes' => null, 'is_active' => true, ...$overrides,
    ];
}

it('starts a fresh store as a single shop and follows the data while unsaved', function (): void {
    Support::actingAs(Role::Manager);

    $this->getJson(BRANCH_MODE_URL)->assertOk()
        ->assertJsonPath('multi_branch', false)
        ->assertJsonPath('saved', false)
        ->assertJsonPath('active_locations', 1)
        ->assertJsonPath('default_location.id', StockLocation::defaultId())
        ->assertJsonPath('blockers', []);

    branchModeBranch('ctg');
    $this->getJson(BRANCH_MODE_URL)->assertOk()
        ->assertJsonPath('multi_branch', true)
        ->assertJsonPath('saved', false)
        ->assertJsonPath('active_locations', 2)
        ->assertJsonPath('blockers.0.name_bn', 'শাখা ctg');
});

it('switches to multiple branches and saves who did it', function (): void {
    $user = Support::actingAs(Role::Manager);

    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => true])->assertOk()
        ->assertJsonPath('multi_branch', true)
        ->assertJsonPath('saved', true);

    $row = Setting::query()->findOrFail(BranchMode::KEY);
    expect($row->value)->toBeTrue()
        ->and($row->group)->toBe(SettingGroup::Store)
        ->and($row->updated_by)->toBe($user instanceof User ? $user->id : null);
});

it('refuses a single shop while other branches are active, listing them (409 branch-mode-blocked)', function (): void {
    Support::actingAs(Role::Manager);
    Setting::put(BranchMode::KEY, true, SettingGroup::Store);
    $ctg = branchModeBranch('ctg');
    $syl = branchModeBranch('syl');
    branchModeBranch('old', active: false);

    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => false])
        ->assertConflict()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/branch-mode-blocked')
        ->assertJsonPath('detail', 'Switch the 2 other active branches off before running the store as a single shop.')
        ->assertJsonCount(2, 'blockers')
        ->assertJsonPath('blockers.0.id', $ctg->id)
        ->assertJsonPath('blockers.1.name', $syl->name);

    expect(BranchMode::isMulti())->toBeTrue();
});

it('says it in Bangla', function (): void {
    Support::actingAs(Role::Manager);
    branchModeBranch('ctg');

    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => false], ['Accept-Language' => 'bn'])
        ->assertConflict()
        ->assertJsonPath('detail', 'একক দোকান হিসেবে চালাতে আগে অন্য 1 টি চালু শাখা বন্ধ করুন।');
});

it('allows a single shop once the other branches are switched off', function (): void {
    Support::actingAs(Role::Manager);
    $ctg = branchModeBranch('ctg');
    $ctg->update(['is_active' => false]);

    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => false])->assertOk()
        ->assertJsonPath('multi_branch', false)
        ->assertJsonPath('saved', true);
});

it('refuses to add a branch or switch one back on while single (409 single-branch-mode)', function (): void {
    Support::actingAs(Role::Manager);
    $old = branchModeBranch('old', active: false);
    Setting::put(BranchMode::KEY, false, SettingGroup::Store);

    $this->postJson(BRANCH_MODE_LOCATIONS_URL, branchModeLocationBody())
        ->assertConflict()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/single-branch-mode');
    expect(StockLocation::query()->where('code', 'syl')->exists())->toBeFalse();

    $this->putJson(BRANCH_MODE_LOCATIONS_URL."/{$old->id}", branchModeLocationBody(['is_active' => true]))
        ->assertConflict()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/single-branch-mode');
    expect($old->fresh()?->is_active)->toBeFalse();

    // Editing the shop itself, or a switched-off location, still works.
    $this->putJson(BRANCH_MODE_LOCATIONS_URL.'/'.StockLocation::defaultId(), branchModeLocationBody(['name' => 'Smart Junior · Mirpur']))
        ->assertOk()->assertJsonPath('name', 'Smart Junior · Mirpur');
    $this->putJson(BRANCH_MODE_LOCATIONS_URL."/{$old->id}", branchModeLocationBody(['is_active' => false, 'name' => 'Old shop']))
        ->assertOk()->assertJsonPath('is_active', false);

    // Turning multiple branches on unlocks both.
    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => true])->assertOk();
    $this->postJson(BRANCH_MODE_LOCATIONS_URL, branchModeLocationBody())->assertCreated();
    $this->putJson(BRANCH_MODE_LOCATIONS_URL."/{$old->id}", branchModeLocationBody(['is_active' => true]))->assertOk()->assertJsonPath('is_active', true);
});

it('validates the body', function (): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BRANCH_MODE_URL, [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'multi_branch');
    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => 'sometimes'])->assertUnprocessable();
});

describe('role matrix', function (): void {
    it('guards the read with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BRANCH_MODE_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards the write with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(BRANCH_MODE_URL, ['multi_branch' => true]));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('answers 401 to a guest', function (): void {
        $this->getJson(BRANCH_MODE_URL)->assertUnauthorized();
        $this->putJson(BRANCH_MODE_URL, ['multi_branch' => true])->assertUnauthorized();
    });
});

it('gives each blocker the units still on hand there', function (): void {
    Support::actingAs(Role::Manager);
    Setting::put(BranchMode::KEY, true, SettingGroup::Store);
    $ctg = branchModeBranch('ctg');
    $syl = branchModeBranch('syl');
    $a = App\Domain\Catalog\Models\ProductVariant::factory()->create();
    $b = App\Domain\Catalog\Models\ProductVariant::factory()->create();
    Illuminate\Support\Facades\DB::transaction(function () use ($a, $b, $ctg): void {
        app(App\Domain\Inventory\Services\StockLedger::class)->record([
            new App\Domain\Inventory\DTOs\StockMovementLine($a->id, App\Domain\Inventory\Enums\StockMovementType::OpeningBalance, 70),
            new App\Domain\Inventory\DTOs\StockMovementLine($b->id, App\Domain\Inventory\Enums\StockMovementType::OpeningBalance, 4),
        ], $ctg->id);
    });

    $this->getJson(BRANCH_MODE_URL)->assertOk()
        ->assertJsonPath('blockers.0.id', $ctg->id)
        ->assertJsonPath('blockers.0.units', 74)
        ->assertJsonPath('blockers.1.id', $syl->id)
        ->assertJsonPath('blockers.1.units', 0);

    $this->putJson(BRANCH_MODE_URL, ['multi_branch' => false])
        ->assertConflict()
        ->assertJsonPath('blockers.0.units', 74);
});

it('tells every signed-in staff member the branch mode and the active branches on /auth/me', function (): void {
    // An order operator: no settings.view, still gets the switcher data.
    $user = Support::actingAs(Role::OrderOperator);
    expect($user->can(Permission::SettingsView->value))->toBeFalse();
    Setting::put(BranchMode::KEY, true, SettingGroup::Store);
    $ctg = StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram · Agrabad', 'name_bn' => 'চট্টগ্রাম · আগ্রাবাদ', 'type' => 'retail', 'area' => 'Agrabad', 'address_line' => 'Shop 9, CDA Avenue', 'landmark' => 'Opposite the bank', 'is_active' => true]);
    branchModeBranch('old', active: false);

    $response = $this->getJson('/api/v1/admin/auth/me')->assertOk()
        ->assertJsonPath('branches.multi_branch', true)
        ->assertJsonCount(2, 'branches.locations')
        ->assertJsonPath('branches.locations.0.id', StockLocation::defaultId())
        ->assertJsonPath('branches.locations.0.is_default', true)
        ->assertJsonPath('branches.locations.1', ['id' => $ctg->id, 'name' => 'Chattogram · Agrabad', 'name_bn' => 'চট্টগ্রাম · আগ্রাবাদ', 'area' => 'Agrabad', 'type' => 'retail', 'is_default' => false]);

    // Names and area only: no address line, landmark or notes.
    expect((string) $response->getContent())->not->toContain('CDA Avenue')->not->toContain('Opposite the bank');
});

it('reports a single shop on /auth/me', function (): void {
    Support::actingAs(Role::OrderOperator);

    $this->getJson('/api/v1/admin/auth/me')->assertOk()
        ->assertJsonPath('branches.multi_branch', false)
        ->assertJsonCount(1, 'branches.locations');
});
