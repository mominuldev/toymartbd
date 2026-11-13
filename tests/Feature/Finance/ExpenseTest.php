<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\ExpenseCategoriesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Finance\FinanceKit;

beforeEach(function (): void {
    (new ExpenseCategoriesSeeder)->run();
    Storage::fake(MediaUrls::originalsDisk());
});

function rentId(): int
{
    return (int) ExpenseCategory::query()->where('name', 'Rent')->value('id');
}

/** @param array<string, mixed> $overrides */
function expenseBody(array $overrides = []): array
{
    return [
        'category_id' => rentId(),
        'amount' => '25000.00',
        'expense_date' => '2026-11-01',
        'payment_method' => 'bank_transfer',
        'vendor' => 'মিরপুর গুদাম মালিক',
        'reference' => 'RENT-NOV',
        'description' => 'November warehouse rent',
        ...$overrides,
    ];
}

function asStaff(Role $role): User
{
    auth()->forgetGuards();
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

it('enters an expense as pending with nothing on the ledger, then approval posts it (recognised + cash)', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody())
        ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('can.approve', false)->json('id');
    expect(FinancialTransaction::query()->count())->toBe(0);

    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk()->assertJsonPath('status', 'approved');

    $entry = FinancialTransaction::query()->sole();
    expect($entry->type->value)->toBe('rent')
        ->and($entry->direction->value)->toBe('out')
        ->and($entry->amount->toDecimal())->toBe('25000.00')
        ->and($entry->occurred_on->toDateString())->toBe('2026-11-01')
        ->and($entry->is_recognised_revenue)->toBeTrue()
        ->and($entry->is_cash_movement)->toBeTrue()
        ->and($entry->category_id)->toBe(rentId());

    // Approved is immutable: edit and delete are 409; a second approval is 409.
    $this->patchJson("/api/v1/admin/finance/expenses/{$id}", expenseBody(['amount' => '1.00']))->assertStatus(409);
    $this->deleteJson("/api/v1/admin/finance/expenses/{$id}")->assertStatus(409);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertStatus(409);
    expect(FinancialTransaction::query()->count())->toBe(1);
});

it('forbids approving an expense you entered yourself', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody())->assertCreated()->json('id');

    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertForbidden();
    expect(FinancialTransaction::query()->count())->toBe(0);
});

it('posts an unpaid expense as recognised only, and the later payment as cash only', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody(['payment_method' => null]))->assertCreated()->json('id');
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/pay", ['payment_method' => 'bkash', 'paid_on' => '2026-11-05'])->assertOk()
        ->assertJsonPath('payment_method', 'bkash');

    $rows = FinancialTransaction::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and([$rows[0]->source_type, $rows[0]->is_recognised_revenue, $rows[0]->is_cash_movement])->toBe(['expense', true, false])
        ->and([$rows[1]->source_type, $rows[1]->is_recognised_revenue, $rows[1]->is_cash_movement, $rows[1]->occurred_on->toDateString()])->toBe(['expense_payment', false, true, '2026-11-05']);

    $this->postJson("/api/v1/admin/finance/expenses/{$id}/pay", ['payment_method' => 'cash', 'paid_on' => '2026-11-05'])->assertStatus(409);
});

it('voids an approved expense with a reversing entry and never rewrites the original', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody())->json('id');
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/void", [])->assertUnprocessable();
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/void", ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('status', 'void');

    $rows = FinancialTransaction::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->direction->value)->toBe('out')
        ->and($rows[1]->direction->value)->toBe('in')
        ->and($rows[1]->source_type)->toBe('expense_void')
        ->and($rows[1]->amount->toDecimal())->toBe('25000.00');
    // Net effect on the P&L and on cash is zero.
    $net = $rows->reduce(fn ($c, FinancialTransaction $t) => $c->plus($t->signedAmount()), App\Support\Money::zero());
    expect($net->toDecimal())->toBe('0.00');
});

it('rejects with a reason, then a corrected resubmission goes back to pending', function (): void {
    $author = FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody())->json('id');
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/reject", ['reason' => 'Wrong amount'])->assertOk()
        ->assertJsonPath('status', 'rejected')->assertJsonPath('rejected_reason', 'Wrong amount');

    auth()->forgetGuards();
    $this->actingAs($author, 'web');
    $this->patchJson("/api/v1/admin/finance/expenses/{$id}", expenseBody(['amount' => '24000.00']))->assertOk()
        ->assertJsonPath('status', 'pending')->assertJsonPath('amount', '24000.00')->assertJsonPath('rejected_reason', null);
    expect(FinancialTransaction::query()->count())->toBe(0);
});

it('stores a receipt privately, sniffs its content, and serves it only as a download', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $png = UploadedFile::fake()->image('রসিদ.png', 40, 40);
    $id = $this->post('/api/v1/admin/finance/expenses', [...expenseBody(), 'receipt' => $png], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('receipt.mime', 'image/png')->json('id');

    $path = Expense::query()->findOrFail($id)->attachment_path;
    expect($path)->toStartWith('finance/receipts/');
    Storage::disk(MediaUrls::originalsDisk())->assertExists($path);

    $this->get("/api/v1/admin/finance/expenses/{$id}/receipt")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition');

    // A text file renamed .pdf is refused by content.
    $fake = UploadedFile::fake()->createWithContent('receipt.pdf', 'not a pdf at all');
    $this->post("/api/v1/admin/finance/expenses/{$id}/receipt", ['receipt' => $fake], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->deleteJson("/api/v1/admin/finance/expenses/{$id}/receipt")->assertOk()->assertJsonPath('receipt', null);
    Storage::disk(MediaUrls::originalsDisk())->assertMissing($path);
});

it('validates money, dates and the category', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $this->postJson('/api/v1/admin/finance/expenses', expenseBody(['amount' => '0', 'expense_date' => '2999-01-01', 'category_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');

    ExpenseCategory::query()->whereKey(rentId())->update(['is_active' => false]);
    $this->postJson('/api/v1/admin/finance/expenses', expenseBody())->assertStatus(409);
});

it('generates each month\'s recurring copies once, pending approval', function (): void {
    $author = FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody(['is_recurring' => true, 'recurrence' => 'monthly', 'expense_date' => '2026-10-01']))->json('id');
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk();

    Artisan::call('toymart:finance:recurring-expenses', ['--period' => '2026-11']);
    Artisan::call('toymart:finance:recurring-expenses', ['--period' => '2026-11']);
    Artisan::call('toymart:finance:recurring-expenses', ['--period' => '2026-10']); // the template's own month

    $copies = Expense::query()->where('recurrence_parent_id', $id)->get();
    expect($copies)->toHaveCount(1)
        ->and($copies[0]->status->value)->toBe('pending')
        ->and($copies[0]->expense_date->toDateString())->toBe('2026-11-01')
        ->and($copies[0]->amount->toDecimal())->toBe('25000.00')
        ->and($copies[0]->created_by)->toBe($author->id)
        ->and(FinancialTransaction::query()->count())->toBe(1); // only the approved template
});

it('rolls an approved expense into the daily summary on its Dhaka date', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $id = $this->postJson('/api/v1/admin/finance/expenses', expenseBody())->json('id');
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk();

    Artisan::call('toymart:finance:rollup', ['--from' => '2026-11-01', '--to' => '2026-11-01']);
    $row = App\Domain\Finance\Models\DailyFinancialSummary::query()->findOrFail('2026-11-01');
    expect($row->rent->toDecimal())->toBe('25000.00')
        ->and($row->recognised_out->toDecimal())->toBe('25000.00')
        ->and($row->net_profit->toDecimal())->toBe('-25000.00')
        ->and($row->cash_out->toDecimal())->toBe('25000.00');
});

it('manages expense categories; DELETE deactivates', function (): void {
    FinanceKit::actingAs(Role::Manager);
    $parent = (int) ExpenseCategory::query()->where('name', 'Advertising')->value('id');
    $id = $this->postJson('/api/v1/admin/finance/expense-categories', [
        'name' => 'TikTok ads', 'name_bn' => 'টিকটক বিজ্ঞাপন', 'type' => 'marketing', 'parent_id' => $parent,
    ])->assertCreated()->assertJsonPath('resolved_ledger_type', 'marketing')->json('id');

    // Only one level of nesting.
    $this->postJson('/api/v1/admin/finance/expense-categories', ['name' => 'Deeper', 'type' => 'marketing', 'parent_id' => $id])->assertUnprocessable();
    $this->patchJson("/api/v1/admin/finance/expense-categories/{$id}", ['ledger_type' => 'other_expense'])->assertOk()->assertJsonPath('resolved_ledger_type', 'other_expense');
    $this->patchJson("/api/v1/admin/finance/expense-categories/{$id}", ['ledger_type' => 'sale_revenue'])->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/finance/expense-categories/{$id}")->assertOk()->assertJsonPath('is_active', false);
    $this->getJson('/api/v1/admin/finance/expense-categories')->assertOk()->assertJsonCount(count(ExpenseCategoriesSeeder::CATEGORIES) + 1, 'data');
});

it('computes the stat cards on the server', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-08 08:00:00', 'UTC'));
    FinanceKit::actingAs(Role::Accountant);
    $rent = $this->postJson('/api/v1/admin/finance/expenses', expenseBody(['is_recurring' => true, 'recurrence' => 'monthly']))->json('id');
    $ads = $this->postJson('/api/v1/admin/finance/expenses', expenseBody([
        'category_id' => ExpenseCategory::query()->where('name', 'Facebook ads')->value('id'),
        'amount' => '200.00', 'expense_date' => '2026-11-07', 'payment_method' => 'card',
    ]))->json('id');
    $this->postJson('/api/v1/admin/finance/expenses', expenseBody(['amount' => '9.00']))->assertCreated(); // stays pending
    asStaff(Role::Manager);
    $this->postJson("/api/v1/admin/finance/expenses/{$rent}/approve")->assertOk();
    $this->postJson("/api/v1/admin/finance/expenses/{$ads}/approve")->assertOk();

    $this->getJson('/api/v1/admin/finance/expenses/summary?filter[from]=2026-11-02&filter[to]=2026-11-08')->assertOk()
        ->assertJsonPath('period_total', '200.00') // rent is dated 01 Nov, outside 02–08
        ->assertJsonPath('period_top.0.name', 'Facebook ads')
        ->assertJsonPath('month_to_date', '25200.00')
        ->assertJsonPath('recurring_monthly', '25000.00')
        ->assertJsonPath('ad_spend_ratio', null)
        ->assertJsonPath('pending_count', 1);
    $this->getJson('/api/v1/admin/finance/expenses/summary?filter[bogus]=1')->assertStatus(400);
});
