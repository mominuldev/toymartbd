<?php

declare(strict_types=1);

use App\Models\Enums\Permission as P;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every finance report endpoint (CLAUDE.md rule 21). The abilities
 * combine permissions (FinanceReportPolicy), so each case derives its expectation from
 * Role::permissions() — the same matrix the seeder reads.
 */

/** @param  list<list<P>>  $anyOf  allowed when the role holds every permission of at least one group */
function reportAllowed(Role $role, array $anyOf): bool
{
    foreach ($anyOf as $group) {
        if (array_diff(array_map(fn (P $p): string => $p->value, $group), $role->permissionNames()) === []) {
            return true;
        }
    }

    return false;
}

dataset('report endpoints', [
    'P&L' => ['finance/reports/pnl', [[P::FinanceView]]],
    'P&L series' => ['finance/reports/pnl/series', [[P::FinanceView]]],
    'P&L CSV' => ['finance/reports/pnl/export', [[P::FinanceView, P::FinanceExport]]],
    'P&L series CSV' => ['finance/reports/pnl/series/export', [[P::FinanceView, P::FinanceExport]]],
    'profitability' => ['finance/reports/profitability', [[P::FinanceView, P::CatalogViewCost]]],
    'profitability CSV' => ['finance/reports/profitability/export', [[P::FinanceView, P::CatalogViewCost, P::FinanceExport]]],
    'valuation' => ['reports/inventory-valuation', [[P::CatalogViewCost, P::FinanceView], [P::CatalogViewCost, P::InventoryView]]],
    'valuation CSV' => ['reports/inventory-valuation/export', [[P::CatalogViewCost, P::FinanceView, P::FinanceExport], [P::CatalogViewCost, P::InventoryView, P::FinanceExport]]],
    'P&L XLSX' => ['finance/reports/pnl/export?format=xlsx', [[P::FinanceView, P::FinanceExport]]],
    'P&L series XLSX' => ['finance/reports/pnl/series/export?format=xlsx', [[P::FinanceView, P::FinanceExport]]],
    'profitability XLSX' => ['finance/reports/profitability/export?format=xlsx', [[P::FinanceView, P::CatalogViewCost, P::FinanceExport]]],
    'valuation XLSX' => ['reports/inventory-valuation/export?format=xlsx', [[P::CatalogViewCost, P::FinanceView, P::FinanceExport], [P::CatalogViewCost, P::InventoryView, P::FinanceExport]]],
]);

it('refuses an unknown export format or locale with 400', function (string $uri): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh());
    foreach (['format=pdf', 'format=XLSX', 'locale=fr', 'format=xlsx&locale=en-GB'] as $bad) {
        $this->get('/api/v1/admin/'.$uri.'?'.$bad, ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
    }
    // Both formats and both locales are accepted.
    $this->get('/api/v1/admin/'.$uri.'?format=csv&locale=bn')->assertOk();
    $this->get('/api/v1/admin/'.$uri.'?format=xlsx&locale=en')->assertOk();
})->with(['finance/reports/pnl/export', 'finance/reports/pnl/series/export', 'finance/reports/profitability/export', 'reports/inventory-valuation/export']);

it('guards each report endpoint for every role', function (string $uri, array $anyOf): void {
    foreach (Role::cases() as $role) {
        RoleMatrix::assertFor($this, $role, reportAllowed($role, $anyOf), fn () => $this->get('/api/v1/admin/'.$uri, ['Accept' => 'application/json']));
    }
})->with('report endpoints');

it('lets only super_admin, manager and accountant see the P&L; inventory staff may value stock', function (): void {
    $pnl = array_values(array_filter(Role::cases(), fn (Role $r): bool => reportAllowed($r, [[P::FinanceView]])));
    $valuation = array_values(array_filter(Role::cases(), fn (Role $r): bool => reportAllowed($r, [[P::CatalogViewCost, P::FinanceView], [P::CatalogViewCost, P::InventoryView]])));

    expect($pnl)->toBe([Role::SuperAdmin, Role::Manager, Role::Accountant])
        ->and($valuation)->toBe([Role::SuperAdmin, Role::Manager, Role::InventoryStaff, Role::Accountant]);
});

it('refuses a guest with 401', function (string $uri): void {
    $this->getJson('/api/v1/admin/'.$uri)->assertUnauthorized();
})->with(['finance/reports/pnl', 'finance/reports/profitability', 'reports/inventory-valuation']);
