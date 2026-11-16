<?php

declare(strict_types=1);

use App\Domain\Finance\Models\CapitalHolder;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Finance\Capital\CapitalKit;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

// The additions the Capital screen (Figma B · 32) needs: holder role, reversed_at, meta.total,
// the overall last entry, the staff picker and the ledger description.

it('stores a holder role (owner by default, partner when asked) and audits it as a plain value', function (): void {
    FinanceKit::actingAs(Role::SuperAdmin);

    $this->postJson(CapitalKit::URL.'/holders', ['name' => 'Mominul Islam'])->assertCreated()->assertJsonPath('role', 'owner');
    $id = $this->postJson(CapitalKit::URL.'/holders', ['name' => 'Farhana Akter', 'name_bn' => 'ফারহানা আক্তার', 'role' => 'partner'])
        ->assertCreated()->assertJsonPath('role', 'partner')->json('id');
    $this->postJson(CapitalKit::URL.'/holders', ['name' => 'X Y', 'role' => 'investor'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'role');

    $this->patchJson(CapitalKit::URL."/holders/{$id}", ['role' => 'owner'])->assertOk()->assertJsonPath('role', 'owner');
    $audit = AuditSensitive::query()->where('action', AuditAction::CapitalHolderSaved->value)->where('model_id', $id)->latest('id')->firstOrFail();
    expect($audit->before)->toBe(['role' => 'partner'])->and($audit->after)->toBe(['role' => 'owner']);
});

it('shows the role and whether a holder has a staff login on the overview, and the latest entry overall', function (): void {
    $user = FinanceKit::actingAs(Role::Accountant);
    $owner = CapitalHolder::query()->create(['name' => 'Mominul Islam', 'role' => 'owner', 'user_id' => $user->id]);
    $partner = CapitalHolder::query()->create(['name' => 'Farhana Akter', 'name_bn' => 'ফারহানা আক্তার', 'role' => 'partner']);
    CapitalKit::record(CapitalKit::body($owner, ['occurred_on' => '2026-11-02', 'amount' => '50000.00']));
    CapitalKit::record(CapitalKit::body($partner, ['occurred_on' => '2026-11-13', 'kind' => 'drawing', 'amount' => '30000.00']));

    $this->getJson(CapitalKit::URL)->assertOk()
        // Owners before partners, whatever the names.
        ->assertJsonPath('holders.0.holder.name', 'Mominul Islam')
        ->assertJsonPath('holders.0.holder.role', 'owner')
        ->assertJsonPath('holders.0.holder.has_staff_login', true)
        ->assertJsonPath('holders.1.holder.role', 'partner')
        ->assertJsonPath('holders.1.holder.has_staff_login', false)
        ->assertJsonPath('totals.last_entry.occurred_on', '2026-11-13')
        ->assertJsonPath('totals.last_entry.kind', 'drawing')
        ->assertJsonPath('totals.last_entry.amount', '30000.00')
        ->assertJsonPath('totals.last_entry.holder_name', 'Farhana Akter')
        ->assertJsonPath('totals.last_entry.holder_name_bn', 'ফারহানা আক্তার');
});

it('has no overall last entry before anything is recorded', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $this->getJson(CapitalKit::URL)->assertOk()->assertJsonPath('totals.last_entry', null);
});

it('says when an entry was reversed and counts every match in meta.total', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $holder = CapitalKit::holder();
    $id = CapitalKit::record(CapitalKit::body($holder, ['kind' => 'drawing', 'amount' => '25000.00', 'occurred_on' => '2026-10-28']))->json('id');
    CapitalKit::record(CapitalKit::body($holder, ['occurred_on' => '2026-11-01']));
    $reversal = CapitalKit::reverse($id)->json('id');

    $entries = $this->getJson(CapitalKit::URL.'/entries?sort=occurred_on')->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.reversed_by_id', $reversal)
        ->assertJsonPath('data.0.reversed_on', now('Asia/Dhaka')->toDateString())
        ->assertJsonPath('data.0.holder.role', 'owner')
        ->assertJsonPath('data.1.reversed_at', null);
    expect($entries->json('data.0.reversed_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($entries->json('meta.next_cursor'))->toBeNull();

    $this->getJson(CapitalKit::URL.'/entries?filter[kind]=contribution')->assertOk()->assertJsonPath('meta.total', 1);
});

it('describes capital ledger rows as holder · method', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $holder = CapitalKit::holder();
    $id = CapitalKit::record(CapitalKit::body($holder, ['method' => 'bkash']))->json('id');
    CapitalKit::record(CapitalKit::body($holder, ['kind' => 'drawing', 'method' => 'bank_transfer']));
    CapitalKit::reverse($id);

    expect(FinancialTransaction::query()->orderBy('id')->pluck('description')->all())->toBe([
        'Mominul Islam · bKash',
        'Mominul Islam · Bank',
        "Mominul Islam · bKash · reverses #{$id}",
    ]);
    $this->getJson('/api/v1/admin/finance/ledger?filter[type]=owner_capital_in,owner_drawing')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.description', fn (string $d): bool => str_starts_with($d, 'Mominul Islam · '));
});

it('lists active staff not yet linked to a holder, names only', function (): void {
    $me = FinanceKit::actingAs(Role::SuperAdmin);
    $linked = User::factory()->create(['name' => 'Linked Person']);
    User::factory()->create(['name' => 'Gone Person', 'is_active' => false]);
    User::factory()->create(['name' => 'Rina Akter']);
    CapitalHolder::query()->create(['name' => 'Linked Person', 'user_id' => $linked->id]);

    $names = collect($this->getJson(CapitalKit::URL.'/staff')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toContain('Rina Akter')->toContain($me->name)
        ->not->toContain('Linked Person')->not->toContain('Gone Person')
        ->and(array_keys($this->getJson(CapitalKit::URL.'/staff')->json('data.0')))->toBe(['id', 'name']);
});

it('guards GET /finance/capital/staff with finance.manage_capital', function (Role $role, bool $allowed): void {
    (new RolesAndPermissionsSeeder)->run();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CapitalKit::URL.'/staff'));
})->with(RoleMatrix::dataset(Permission::FinanceManageCapital));
