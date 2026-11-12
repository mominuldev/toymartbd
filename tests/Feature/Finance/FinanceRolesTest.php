<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\ExpenseCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    (new ExpenseCategoriesSeeder)->run();
});

function pendingExpense(): Expense
{
    return Expense::query()->create([
        'category_id' => ExpenseCategory::query()->where('name', 'Utilities')->value('id'),
        'amount' => '3200.00',
        'expense_date' => '2026-11-10',
        'status' => 'pending',
        'vendor' => 'ডেসকো',
        'created_by' => User::factory()->create()->id,
    ]);
}

it('restricts finance to super admin, manager and accountant', function (): void {
    $allowed = array_values(array_filter(Role::cases(), static fn (Role $r): bool => RoleMatrix::allows($r, Permission::FinanceView)));
    expect(array_map(static fn (Role $r): string => $r->value, $allowed))->toBe(['super_admin', 'manager', 'accountant']);
});

it('guards GET /finance/ledger', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/finance/ledger'));
})->with(RoleMatrix::dataset(Permission::FinanceView));

it('guards GET /finance/receivables', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/finance/receivables'));
})->with(RoleMatrix::dataset(Permission::FinanceView));

it('guards GET /finance/expenses and /finance/expense-categories', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/finance/expenses'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/finance/expense-categories'));
    $expense = pendingExpense();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson("/api/v1/admin/finance/expenses/{$expense->id}"));
})->with(RoleMatrix::dataset(Permission::FinanceView));

it('guards expense writes', function (Role $role, bool $allowed): void {
    $category = (int) ExpenseCategory::query()->where('name', 'Rent')->value('id');
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/finance/expenses', [
        'category_id' => $category, 'amount' => '10.00', 'expense_date' => '2026-11-10',
    ]), 201);
    $expense = pendingExpense();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson("/api/v1/admin/finance/expenses/{$expense->id}", [
        'category_id' => $category, 'amount' => '11.00', 'expense_date' => '2026-11-10',
    ]));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/finance/expenses/{$expense->id}/approve"));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/finance/expenses/{$expense->id}/void", ['reason' => 'Duplicate entry']));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/finance/expense-categories', ['name' => "Cat {$role->value}", 'type' => 'other']), 201);
    $other = pendingExpense();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson("/api/v1/admin/finance/expenses/{$other->id}"), 204);
})->with(RoleMatrix::dataset(Permission::FinanceManageExpenses));

it('guards POST /finance/orders/{order}/cod-receipts', function (Role $role, bool $allowed): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        "/api/v1/admin/finance/orders/{$order->id}/cod-receipts",
        ['amount' => '1.00', 'received_on' => now('Asia/Dhaka')->toDateString()],
        ['Idempotency-Key' => 'role-'.$role->value.'-key'],
    ), 201);
})->with(RoleMatrix::dataset(Permission::FinanceReconcileCod));

it('filters the ledger against an allowlist; an unlisted filter or sort is a 400', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());

    $this->getJson('/api/v1/admin/finance/ledger?filter[type]=sale_revenue,cogs&filter[recognised]=true')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/admin/finance/ledger?filter[source_type]=order&filter[source_id]={$order->id}&filter[cash]=false")->assertOk()->assertJsonCount(4, 'data');
    $this->getJson('/api/v1/admin/finance/ledger?filter[cash]=true')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/finance/ledger?filter[from]=2999-01-01')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/finance/ledger?sort=-amount')->assertOk()->assertJsonPath('data.0.type', 'sale_revenue');

    $this->getJson('/api/v1/admin/finance/ledger?filter[amount]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/finance/ledger?sort=description')->assertStatus(400);
    $this->getJson('/api/v1/admin/finance/ledger?filter[type]=bogus')->assertStatus(400);
    $this->getJson('/api/v1/admin/finance/ledger?filter[from]=2026-13-40')->assertStatus(400);
});
