<?php

declare(strict_types=1);

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Finance\FinanceKit;
use Tests\Support\RoleMatrix;

/*
 * Finance premium pass (2026-11-16) — the figures the redesigned screens read from the server
 * instead of counting or adding in the browser (rule 1): the ledger's order number, the
 * expense-category counts, the settlement list's review state filter, courier allowlist and
 * "statements to review" count, and a settlement's per-type summary of undecided lines.
 */

beforeEach(function (): void {
    $this->seed(DeliverySeeder::class);
});

/** A delivered COD order with a delivered shipment at `$courier` (grand total 1170). */
function pagesShipment(string $consignment, string $courier = 'steadfast'): array
{
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    $provider = (int) DB::table('courier_providers')->where('code', $courier)->value('id');
    $id = DB::table('shipments')->insertGetId([
        'order_id' => $order->id, 'provider_id' => $provider, 'number' => 'SHP-'.Str::random(8),
        'consignment_id' => $consignment, 'status' => 'delivered', 'cod_amount' => '1170.00', 'declared_value' => '1170.00',
        'charge' => '130.00', 'cod_fee' => '11.70', 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$order->refresh(), $id];
}

/** @param  list<array<string, string>>  $lines */
function pagesImport(string $reference, array $lines, string $courier = 'steadfast'): int
{
    return (int) test()->postJson('/api/v1/admin/finance/settlements', [
        'provider' => $courier, 'reference' => $reference, 'received_at' => now('Asia/Dhaka')->toDateString(), 'lines' => $lines,
    ], ['Idempotency-Key' => 'pages-'.$reference])->assertCreated()->json('id');
}

it('gives an order’s own ledger rows the order number, and every other row none', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    app(Ledger::class)->record(new LedgerEntry(LedgerEntryType::OtherExpense, Direction::Out, Money::of('50.00'), CarbonImmutable::now('Asia/Dhaka'), 'test', 7, true, true));

    $rows = collect($this->getJson('/api/v1/admin/finance/ledger')->assertOk()->json('data'));

    $own = $rows->where('source_type', 'order');
    expect($own)->not->toBeEmpty()
        ->and($own->pluck('order_number')->unique()->values()->all())->toBe([$order->number])
        ->and($rows->firstWhere('source_type', 'test')['order_number'])->toBeNull();
});

it('counts categories and filed expenses on the server for the categories caption', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $rent = ExpenseCategory::query()->create(['name' => 'Rent', 'name_bn' => 'ভাড়া', 'type' => 'operating', 'is_fixed' => true, 'is_active' => true]);
    ExpenseCategory::query()->create(['name' => 'Trade fair', 'name_bn' => 'বাণিজ্য মেলা', 'type' => 'other', 'is_fixed' => false, 'is_active' => false]);
    foreach (['35000.00', '1200.00'] as $amount) {
        $this->post('/api/v1/admin/finance/expenses', ['amount' => $amount, 'category_id' => $rent->id, 'expense_date' => now('Asia/Dhaka')->toDateString(), 'description' => 'মিরপুর গুদাম'], ['Accept' => 'application/json'])->assertCreated();
    }

    $categories = ExpenseCategory::query()->count();
    $response = $this->getJson('/api/v1/admin/finance/expense-categories')->assertOk();

    expect($response->json('meta.category_count'))->toBe($categories)
        ->and($response->json('meta.active_count'))->toBe(ExpenseCategory::query()->where('is_active', true)->count())
        ->and($response->json('meta.expense_count'))->toBe(Expense::query()->count())
        ->and($response->json('meta.expense_count'))->toBe(2);
});

it('filters settlements by review state and courier, counts the statements waiting for a decision, and 400s an unknown value', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    pagesShipment('SFA1');
    pagesShipment('SFA2');
    $clean = pagesImport('SFC-CLEAN', [['consignment_id' => 'SFA1', 'cod_amount' => '1170.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70']]);
    $disputed = pagesImport('SFC-SHORT', [['consignment_id' => 'SFA2', 'cod_amount' => '1070.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70']]);
    $done = pagesImport('SFC-DONE', [['consignment_id' => 'NOT-OURS', 'cod_amount' => '10.00']]);
    $item = DB::table('courier_settlement_items')->where('settlement_id', $done)->value('id');
    $this->postJson("/api/v1/admin/finance/settlements/{$done}/items/{$item}/review", ['decision' => 'excluded', 'note' => 'অন্য ব্যবসায়ীর'])->assertOk();
    $this->postJson("/api/v1/admin/finance/settlements/{$done}/confirm", [], ['Idempotency-Key' => 'pages-confirm'])->assertOk();

    $ids = fn (string $query): array => collect($this->getJson('/api/v1/admin/finance/settlements'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids('?filter[status]=received'))->toBe([$clean])
        ->and($ids('?filter[status]=disputed'))->toBe([$disputed])
        ->and($ids('?filter[status]=reconciled'))->toBe([$done])
        ->and($ids('?filter[provider]=steadfast'))->toBe(collect([$clean, $disputed, $done])->sort()->values()->all())
        ->and($ids('?filter[provider]=pathao'))->toBe([]);

    $list = $this->getJson('/api/v1/admin/finance/settlements?filter[status]=reconciled')->assertOk();
    expect($list->json('meta.disputed_count'))->toBe(1)
        ->and(collect($list->json('meta.providers'))->pluck('code')->all())->toContain('steadfast');

    $this->getJson('/api/v1/admin/finance/settlements?filter[status]=open')->assertStatus(400);
    $this->getJson('/api/v1/admin/finance/settlements?filter[provider]=redx-unknown')->assertStatus(400);
    $this->getJson('/api/v1/admin/finance/settlements?filter[courier]=steadfast')->assertStatus(400);
});

it('summarises a statement’s undecided lines per variance type with the gap the server summed', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    pagesShipment('SFB1');
    pagesShipment('SFB2');
    $id = pagesImport('SFC-SUM', [
        ['consignment_id' => 'SFB1', 'cod_amount' => '1070.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70'],
        ['consignment_id' => 'SFB2', 'cod_amount' => '1120.00', 'delivery_charge' => '130.00', 'cod_fee' => '11.70'],
        ['consignment_id' => 'SF-NOT-OURS', 'cod_amount' => '500.00'],
    ]);

    $detail = $this->getJson("/api/v1/admin/finance/settlements/{$id}")->assertOk();
    $byType = collect($detail->json('pending_by_type'))->keyBy('variance_type');

    expect($detail->json('line_count'))->toBe(3)
        ->and($byType['short']['count'])->toBe(2)
        ->and($byType['short']['amount'])->toBe('150.00')
        ->and($byType['unknown_consignment']['count'])->toBe(1)
        ->and($detail->json('unresolved_count'))->toBe(3);

    // The list rows stay light: no per-line summary there.
    $row = collect($this->getJson('/api/v1/admin/finance/settlements')->json('data'))->firstWhere('id', $id);
    expect($row)->not->toHaveKey('pending_by_type')->and($row)->not->toHaveKey('line_count');
});

it('keeps the redesigned reads behind finance.view', function (): void {
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson('/api/v1/admin/finance/expense-categories'));
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson('/api/v1/admin/finance/settlements?filter[status]=disputed'));
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson('/api/v1/admin/finance/ledger?filter[source_type]=order'));
});
