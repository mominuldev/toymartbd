<?php

declare(strict_types=1);

use App\Domain\Finance\Models\CapitalEntry;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Finance\Capital\CapitalKit;
use Tests\Support\RoleMatrix;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
});

it('grants finance.manage_capital to super admin, manager and accountant by default, and counts it as money', function (): void {
    $allowed = array_values(array_filter(Role::cases(), static fn (Role $r): bool => RoleMatrix::allows($r, Permission::FinanceManageCapital)));
    expect(array_map(static fn (Role $r): string => $r->value, $allowed))->toBe(['super_admin', 'manager', 'accountant'])
        ->and(Permission::FinanceManageCapital->revealsMoney())->toBeTrue()
        ->and(Permission::FinanceManageCapital->isOwnerOnly())->toBeFalse();
});

it('answers 401 without a session', function (): void {
    $this->getJson(CapitalKit::URL)->assertUnauthorized();
    $this->getJson(CapitalKit::URL.'/entries')->assertUnauthorized();
    $this->getJson(CapitalKit::URL.'/holders')->assertUnauthorized();
    $this->postJson(CapitalKit::URL.'/entries', [], ['Idempotency-Key' => CapitalKit::nextKey()])->assertUnauthorized();
    $this->postJson(CapitalKit::URL.'/holders', ['name' => 'X'])->assertUnauthorized();
});

it('guards GET /finance/capital, /entries and /holders with finance.view', function (Role $role, bool $allowed): void {
    CapitalKit::holder();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CapitalKit::URL));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CapitalKit::URL.'/entries'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(CapitalKit::URL.'/holders'));
})->with(RoleMatrix::dataset(Permission::FinanceView));

it('guards recording and reversing with finance.manage_capital', function (Role $role, bool $allowed): void {
    $holder = CapitalKit::holder();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => CapitalKit::record(CapitalKit::body($holder)), 201);

    $entry = CapitalEntry::query()->create([
        'kind' => 'drawing', 'holder_id' => $holder->id, 'amount' => '10.00',
        'occurred_on' => '2026-11-10', 'method' => 'cash',
    ]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => CapitalKit::reverse($entry->id), 201);
    if (! $allowed) {
        expect(CapitalEntry::query()->count())->toBe(1);
    }
})->with(RoleMatrix::dataset(Permission::FinanceManageCapital));

it('guards adding and changing holders with finance.manage_capital', function (Role $role, bool $allowed): void {
    $holder = CapitalKit::holder();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(CapitalKit::URL.'/holders', ['name' => "Partner {$role->value}"]), 201);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson(CapitalKit::URL."/holders/{$holder->id}", ['name_bn' => 'মমিনুল']));
})->with(RoleMatrix::dataset(Permission::FinanceManageCapital));
