<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Seo\Models\Redirect;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\ExpenseCategoriesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

/*
 * 403 role-matrix tests for the admin routes the P9 security review found without one
 * (CLAUDE.md rule 21). Each had a functional test, some a single-role 403, but not the
 * matrix. The registry (tests/Support/role-matrix-routes.php) now lists them.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => 'https://toymartbd.com', 'services.storefront.revalidate_secret' => '']);
    Storage::fake(MediaUrls::originalsDisk());
});

it('guards the dashboard (dashboard.view) and its export (dashboard.view + reports.export)', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/dashboard'));

    $export = $allowed && RoleMatrix::allows($role, Permission::ReportsExport);
    RoleMatrix::assertFor($this, $role, $export, fn () => $this->get('/api/v1/admin/dashboard/export', ['Accept' => 'application/json']));
})->with(RoleMatrix::dataset(Permission::DashboardView));

it('guards the SERP preview with seo.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/seo/serp-preview', ['title' => 'Wooden blocks', 'description' => 'Short.', 'url' => '/products/x']));
})->with(RoleMatrix::dataset(Permission::SeoView));

it('guards redirect import and delete with seo.manage', function (Role $role, bool $allowed): void {
    $csv = UploadedFile::fake()->createWithContent('r.csv', "from_path,to_path,status_code,note\n/old-{$role->value},/toys,301,\n");
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->post('/api/v1/admin/seo/redirects/import', ['file' => $csv], ['Accept' => 'application/json']));

    $redirect = Redirect::query()->create(['from_path' => '/gone-'.$role->value, 'to_path' => '/toys', 'status_code' => 301, 'source' => Redirect::SOURCE_MANUAL, 'is_active' => true]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson("/api/v1/admin/seo/redirects/{$redirect->id}"), 204);
})->with(RoleMatrix::dataset(Permission::SeoManage));

it('guards GET /shipments/{shipment} with shipments.view', function (Role $role, bool $allowed): void {
    $this->seed(DeliverySeeder::class);
    $order = FinanceKit::shippedOrder();
    $id = DB::table('shipments')->insertGetId([
        'order_id' => $order->id, 'provider_id' => (int) DB::table('courier_providers')->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-'.Str::random(8), 'consignment_id' => 'SF-'.Str::random(6), 'status' => 'booked',
        'cod_amount' => '1170.00', 'declared_value' => '1170.00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson("/api/v1/admin/shipments/{$id}"));
})->with(RoleMatrix::dataset(Permission::ShipmentsView));

it('guards confirming a COD settlement with finance.reconcile_cod (a policy pass meets the 409 of an already-reconciled one)', function (Role $role, bool $allowed): void {
    $this->seed(DeliverySeeder::class);
    $settlement = CourierSettlement::query()->create([
        'provider_id' => (int) DB::table('courier_providers')->where('code', 'steadfast')->value('id'),
        'reference' => 'R-'.$role->value, 'received_at' => '2026-11-10', 'status' => 'reconciled', 'source' => 'manual',
    ]);

    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        "/api/v1/admin/finance/settlements/{$settlement->id}/confirm",
        [],
        ['Idempotency-Key' => 'confirm-'.$role->value],
    ), 409);
})->with(RoleMatrix::dataset(Permission::FinanceReconcileCod));

function gapExpense(array $attributes = []): Expense
{
    if (! ExpenseCategory::query()->exists()) {
        (new ExpenseCategoriesSeeder)->run();
    }

    return Expense::query()->create($attributes + [
        'category_id' => ExpenseCategory::query()->where('name', 'Utilities')->value('id'),
        'amount' => '3200.00',
        'expense_date' => '2026-11-10',
        'status' => 'pending',
        'vendor' => 'ডেসকো',
        'created_by' => User::factory()->create()->id,
    ]);
}

it('guards the expense summary and receipt download with finance.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/finance/expenses/summary'));

    $path = 'finance/receipts/'.Str::random(8).'.png';
    Storage::disk(MediaUrls::originalsDisk())->put($path, 'png-bytes');
    $expense = gapExpense(['attachment_path' => $path, 'attachment_name' => 'রসিদ.png', 'attachment_mime' => 'image/png']);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get("/api/v1/admin/finance/expenses/{$expense->id}/receipt", ['Accept' => 'application/json']));
})->with(RoleMatrix::dataset(Permission::FinanceView));

it('guards expense reject, pay and receipts, and category edit/archive, with finance.manage_expenses', function (Role $role, bool $allowed): void {
    $expense = gapExpense();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/finance/expenses/{$expense->id}/reject", ['reason' => 'Duplicate bill']));

    $pending = gapExpense();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/finance/expenses/{$pending->id}/pay", ['payment_method' => 'cash', 'paid_on' => '2026-11-10']));

    $pending = gapExpense();
    $png = UploadedFile::fake()->image('receipt.png', 20, 20);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->post("/api/v1/admin/finance/expenses/{$pending->id}/receipt", ['receipt' => $png], ['Accept' => 'application/json']));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson("/api/v1/admin/finance/expenses/{$pending->id}/receipt"));

    $category = ExpenseCategory::query()->create(['name' => 'Gap '.$role->value, 'type' => 'other']);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson("/api/v1/admin/finance/expense-categories/{$category->id}", ['name' => 'Gap renamed '.$role->value]));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson("/api/v1/admin/finance/expense-categories/{$category->id}"));
})->with(RoleMatrix::dataset(Permission::FinanceManageExpenses));
