<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Finance\Actions\ApproveExpense;
use App\Domain\Finance\Actions\MarkExpensePaid;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Listeners\Purchasing\BookThirdPartyLandedCosts;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Finance\Services\DailySummaryRollup;
use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Purchasing\Actions\ReceivePurchase;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ExpenseCategoriesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Purchasing\PurchasingKit as Kit;

/*
 * Landed costs charged by a third party (owner decision 2026-11-13) become a capitalised
 * expense: cash out when paid, never a recognised P&L cost — the cost is already inside the
 * landed unit cost and reaches the P&L as COGS on sale.
 */

function receiveViaForwarder(string $freight, string $customs, ?string $method): void
{
    $variant = ProductVariant::factory()->create();
    $po = Kit::purchase([[$variant, 10, '100']], Supplier::factory()->create());
    app(ReceivePurchase::class)->handle($po, new ReceiptData(
        [['purchase_item_id' => $po->items[0]->id, 'quantity' => 10]],
        Money::of($freight), Money::of($customs), Money::zero(), LandedCostMethod::Value, null, false, null,
        'Sea Hawk Forwarders · সি হক', 'BL-77821', $method,
    ));
}

beforeEach(function (): void {
    (new ExpenseCategoriesSeeder)->run();
    $this->approver = User::factory()->create();
});

it('seeds the capitalised Freight & customs (stock) category in English and Bangla', function (): void {
    $row = DB::table('expense_categories')->where('name', BookThirdPartyLandedCosts::CATEGORY)->sole();

    expect((bool) $row->capitalised)->toBeTrue()
        ->and($row->name_bn)->toBe('পরিবহন ও শুল্ক (স্টক)');
});

it('books third-party freight and customs as one pending expense with vendor and reference', function (): void {
    receiveViaForwarder('500', '700', 'bank_transfer');

    $expense = Expense::query()->with('category')->sole();
    expect($expense->category->name)->toBe(BookThirdPartyLandedCosts::CATEGORY)
        ->and($expense->amount->toDecimal())->toBe('1200.00')
        ->and($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->vendor)->toBe('Sea Hawk Forwarders · সি হক')
        ->and($expense->reference)->toBe('BL-77821')
        ->and($expense->payment_method)->toBe(ExpensePaymentMethod::BankTransfer)
        ->and($expense->description)->toStartWith('GRN-')
        ->and($expense->description)->toContain('freight 500.00, customs/duty 700.00');
});

it('writes no expense when the supplier charged the costs, or there were none', function (): void {
    $variant = ProductVariant::factory()->create();
    $po = Kit::purchase([[$variant, 4, '100']]);
    Kit::receive($po, [$po->items[0]->id => 2], freight: '50');
    Kit::receive($po->refresh(), [$po->items[0]->id => 2], costsToSupplier: false);

    expect(Expense::query()->count())->toBe(0);
});

it('records the cash out on approval and leaves the P&L and the daily rollup unchanged', function (): void {
    $day = '2026-11-13';
    CarbonImmutable::setTestNow(CarbonImmutable::parse($day.' 12:00', 'Asia/Dhaka'));
    $period = ReportPeriod::of($day, $day);
    $pnl = static function () use ($period): string {
        $statement = app(ProfitAndLoss::class)->statement($period, 'none');

        return (string) json_encode([$statement['blocks'], $statement['totals'], $statement['unmapped'], $statement['expenses_by_category'], $statement['reconciliation']]);
    };
    $before = $pnl();

    receiveViaForwarder('500', '700', 'cash');
    app(ApproveExpense::class)->handle(Expense::query()->sole(), $this->approver->id);

    $entry = DB::table('financial_transactions')->where('source_type', 'expense')->sole();
    expect($entry->type)->toBe('supplier_payment')
        ->and($entry->direction)->toBe('out')
        ->and((bool) $entry->is_cash_movement)->toBeTrue()
        ->and((bool) $entry->is_recognised_revenue)->toBeFalse()
        ->and(bcadd((string) $entry->amount, '0', 2))->toBe('1200.00')
        ->and($pnl())->toBe($before)
        ->and(app(ProfitAndLoss::class)->statement($period, 'none')['cash']['out']->toDecimal())->toBe('1200.00')
        ->and(app(FinanceInvariant::class)->drift())->toBe([]);

    $row = app(DailySummaryRollup::class)->row($day);
    expect(bccomp((string) $row['recognised_out'], '0', 2))->toBe(0)
        ->and(bcadd((string) $row['cash_out'], '0', 2))->toBe('1200.00')
        ->and(bccomp((string) $row['net_profit'], '0', 2))->toBe(0);

    CarbonImmutable::setTestNow();
});

it('posts an unpaid capitalised expense with no cash until it is paid, then cash only', function (): void {
    receiveViaForwarder('300', '0', null);
    $expense = app(ApproveExpense::class)->handle(Expense::query()->sole(), $this->approver->id);

    expect(DB::table('financial_transactions')->where('is_cash_movement', true)->count())->toBe(0)
        ->and(DB::table('financial_transactions')->where('is_recognised_revenue', true)->count())->toBe(0);

    app(MarkExpensePaid::class)->handle($expense, ExpensePaymentMethod::Bkash, CarbonImmutable::parse('2026-11-14'));

    $cash = DB::table('financial_transactions')->where('is_cash_movement', true)->sole();
    expect($cash->type)->toBe('supplier_payment')
        ->and((bool) $cash->is_recognised_revenue)->toBeFalse()
        ->and(bcadd((string) $cash->amount, '0', 2))->toBe('300.00');
});

it('accepts the vendor, reference and payment method through the receive endpoint', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(App\Models\Enums\Role::Manager)->create()->refresh(), 'web');
    $po = Kit::purchase([[ProductVariant::factory()->create(), 2, '100']]);

    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", [
        'lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 2]],
        'customs_duty' => '80', 'costs_paid_to_supplier' => false,
        'costs_vendor' => 'Chattogram C&F', 'costs_reference' => 'BOE-1', 'costs_payment_method' => 'nagad',
    ], ['Idempotency-Key' => (string) Illuminate\Support\Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('receipts.0.costs_vendor', 'Chattogram C&F')
        ->assertJsonPath('receipts.0.payable_amount', '200.00');

    expect(Expense::query()->sole()->amount->toDecimal())->toBe('80.00');
    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", ['lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 0]], 'costs_payment_method' => 'gold'], ['Idempotency-Key' => (string) Illuminate\Support\Str::uuid()])
        ->assertUnprocessable();
});
