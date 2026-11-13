<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\SupplierBalances;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\BrandDemoSeeder;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\CategoryDemoSeeder;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoFinanceSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoPurchasingSeeder;
use Database\Seeders\DemoStaffSeeder;
use Database\Seeders\ExpenseCategoriesSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The back-office demo data — staff logins, purchasing and expenses — written through the
 * domain Actions: every purchase and expense state appears, payables are owed, a landed
 * cost moved a variant's cost, recurring copies were generated, both invariants hold, and
 * a second run adds nothing.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['demo.transactions' => true, 'services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'media.demo_seed_images' => false]);

    $this->seed([
        RolesAndPermissionsSeeder::class, GeographySeeder::class, CatalogReferenceSeeder::class, DeliverySeeder::class,
        ExpenseCategoriesSeeder::class, CategoryDemoSeeder::class, BrandDemoSeeder::class, DemoCatalogSeeder::class,
        DemoInventorySeeder::class,
    ]);
    User::factory()->create(['email' => 'owner@toymart.test'])->assignRole(Role::SuperAdmin->value);
});

afterEach(function (): void {
    expect(Carbon::hasTestNow())->toBeFalse()->and(CarbonImmutable::hasTestNow())->toBeFalse();
});

/** @return array<string, int> */
function backOfficeCounts(): array
{
    return collect(['users', 'suppliers', 'purchases', 'purchase_receipts', 'supplier_payments', 'expenses',
        'stock_movements', 'financial_transactions'])->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();
}

it('seeds staff, purchasing and finance in every state with both invariants intact, idempotently', function (): void {
    $this->seed([DemoStaffSeeder::class, DemoPurchasingSeeder::class, DemoFinanceSeeder::class]);

    // One login per non-owner role.
    foreach (DemoStaffSeeder::EMAILS as $role => $email) {
        expect(User::query()->where('email', $email)->sole()->hasRole($role))->toBeTrue();
    }

    // Every purchase status, receipts at both branches, one archived supplier.
    $statuses = DB::table('purchases')->distinct()->pluck('status')->all();
    foreach (PurchaseStatus::values() as $status) {
        expect($statuses)->toContain($status);
    }
    expect(DB::table('purchase_receipts')->distinct()->count('location_id'))->toBe(2)
        ->and(Supplier::query()->where('status', 'archived')->count())->toBe(1);

    // Payables: some suppliers owed, some fully paid.
    $balances = app(SupplierBalances::class)->forMany(Supplier::all());
    $owed = array_filter($balances, static fn (array $b): bool => $b['balance']->isNegative() === false && ! $b['balance']->isZero());
    $settled = array_filter($balances, static fn (array $b): bool => $b['balance']->isZero() && ! $b['paid']->isZero());
    expect($owed)->not->toBeEmpty()->and($settled)->not->toBeEmpty();

    // A landed cost moved at least one variant's average (and so its cost_price).
    expect(DB::table('purchase_receipt_items')->where('allocated_cost', '>', 0)->whereColumn('avg_cost_before', '!=', 'avg_cost_after')->exists())->toBeTrue();

    // Every expense state, segregation of duties, recurring copies, a capitalised expense approved.
    $expenseStatuses = DB::table('expenses')->distinct()->pluck('status')->all();
    foreach (ExpenseStatus::values() as $status) {
        expect($expenseStatuses)->toContain($status);
    }
    expect(DB::table('expenses')->whereNotNull('approved_by')->whereColumn('approved_by', 'created_by')->exists())->toBeFalse()
        ->and(DB::table('expenses')->whereNotNull('recurrence_parent_id')->count())->toBeGreaterThan(0)
        ->and(DB::table('expenses')->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->where('expense_categories.capitalised', true)->where('expenses.status', 'approved')->exists())->toBeTrue()
        ->and(DB::table('daily_financial_summaries')->count())->toBeGreaterThan(30);

    expect(Artisan::call('toymart:inventory:reconcile'))->toBe(0, Artisan::output())
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0, Artisan::output());

    $before = backOfficeCounts();
    $this->seed([DemoStaffSeeder::class, DemoPurchasingSeeder::class, DemoFinanceSeeder::class]);
    expect(backOfficeCounts())->toBe($before);
});

it('tolerates a database without staff or orders', function (): void {
    $this->seed([DemoPurchasingSeeder::class, DemoFinanceSeeder::class]);

    expect(DB::table('purchases')->count())->toBe(count(DemoPurchasingSeeder::PURCHASES))
        ->and(DB::table('expenses')->whereNotNull('order_id')->count())->toBe(0)
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0);
});
