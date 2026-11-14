<?php

declare(strict_types=1);

use App\Domain\Pos\Actions\HoldSale;
use App\Domain\Pos\Actions\OpenRegisterSession;
use App\Domain\Pos\Models\PosSession;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Support\Money;
use Tests\Feature\Pos\PosKit;
use Tests\Support\RoleMatrix;

/*
 * The 403 role matrix for every POS route (rule 21). Defaults: super_admin and manager hold
 * pos.use + pos.manage, order_operator (the counter) pos.use; the rest neither. Each request
 * works a drawer the signed-in role opened itself — so a 403 is the permission, not ownership.
 */

/** A drawer opened by whoever is signed in now (opened directly, bypassing the permission). */
function rmDrawer(): PosSession
{
    $branch = App\Domain\Inventory\Models\StockLocation::query()->where('code', 'ctg')->first() ?? PosKit::branch();

    return app(OpenRegisterSession::class)->handle($branch->id, 'R-'.auth()->id(), Money::of('1000'), (int) auth()->id());
}

beforeEach(function (): void {
    $this->toy = PosKit::variant(PosKit::branch(), 50, '500.00');
});

it('guards GET /pos/context', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/pos/context'));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards GET /pos/customers', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/pos/customers?phone='.PosKit::PHONE));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/sessions', function (Role $role, bool $allowed): void {
    $branch = App\Domain\Inventory\Models\StockLocation::query()->where('code', 'ctg')->sole();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/pos/sessions', [
        'location_id' => $branch->id, 'register' => 'Register 9', 'opening_float' => '0',
    ]), 201);
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards GET /pos/sessions/{session}', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/sessions/{session}/close', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id.'/close', ['counted_cash' => '1000']));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards GET /pos/sessions/{session}/catalog', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id.'/catalog'));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/sessions/{session}/quote', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id.'/quote', ['lines' => PosKit::lines([$this->toy->id => 1])]));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/sessions/{session}/sales', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => PosKit::charge(rmDrawer(), [$this->toy->id => 1], [['method' => 'cash', 'amount' => '500']], '500.00'), 201);
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards GET /pos/sessions/{session}/holds', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id.'/holds'));
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/sessions/{session}/holds', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/pos/sessions/'.rmDrawer()->id.'/holds', ['lines' => PosKit::lines([$this->toy->id => 1])]), 201);
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards POST /pos/holds/{hold}/resume', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, function () {
        $hold = app(HoldSale::class)->handle(rmDrawer(), [$this->toy->id => 1], null, null, null, (int) auth()->id());

        return $this->postJson("/api/v1/admin/pos/holds/{$hold->id}/resume");
    });
})->with(RoleMatrix::dataset(Permission::PosUse));

it('guards GET /pos/sales/{sale}/receipt', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, function () {
        $sale = app(App\Domain\Pos\Actions\ChargeSale::class)->handle(new App\Domain\Pos\DTOs\SaleData(
            sessionId: rmDrawer()->id,
            lines: [$this->toy->id => 1],
            tenders: [new App\Domain\Pos\DTOs\TenderInput(App\Domain\Payment\Enums\PaymentMethod::Cash, Money::of('500'))],
            expectedTotal: Money::of('500'),
            cashierId: (int) auth()->id(),
        ));

        return $this->getJson("/api/v1/admin/pos/sales/{$sale->id}/receipt");
    });
})->with(RoleMatrix::dataset(Permission::PosUse));

it('grants pos.use to the counter role and pos.manage to managers by default', function (): void {
    expect(Role::OrderOperator->permissions())->toContain(Permission::PosUse)
        ->not->toContain(Permission::PosManage)
        ->and(Role::Manager->permissions())->toContain(Permission::PosUse, Permission::PosManage)
        ->and(Role::Viewer->permissions())->not->toContain(Permission::PosUse)
        ->and(Role::Accountant->permissions())->not->toContain(Permission::PosUse);
});
