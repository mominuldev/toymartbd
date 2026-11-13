<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Purchasing\PurchasingKit as Kit;

/*
 * The admin purchasing API end to end: validation, allowlisted filters, server-computed
 * totals and quotes, lifecycle conflicts and idempotent receipts.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    $this->variant = ProductVariant::factory()->create(['cost_price' => '300.00']);
});

it('creates a supplier with its phone stored E.164, and refuses an 011 number (rule 30)', function (): void {
    $this->postJson('/api/v1/admin/suppliers', ['name' => 'ঢাকা খেলনা ঘর', 'phone' => '01712345678', 'payment_terms_days' => 30, 'opening_balance' => '1500.50'])
        ->assertCreated()
        ->assertJsonPath('phone', '+8801712345678')
        ->assertJsonPath('balance.balance', '1500.50');

    $this->postJson('/api/v1/admin/suppliers', ['name' => 'Citycell Toys', 'phone' => '01112345678'])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');
});

it('lists suppliers with allowlisted filters only', function (): void {
    Supplier::factory()->create(['name' => 'Chittagong Toy Imports']);
    Supplier::factory()->archived()->create(['name' => 'Old Toys Ltd']);

    $this->getJson('/api/v1/admin/suppliers?filter[status]=active&filter[search]=chittagong')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chittagong Toy Imports');
    $this->getJson('/api/v1/admin/suppliers?filter[phone]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/suppliers?filter[status]=gone')->assertStatus(400);
    $this->getJson('/api/v1/admin/suppliers?sort=tin')->assertStatus(400);
});

it('archives and restores a supplier, and refuses a PO for an archived one', function (): void {
    $supplier = Supplier::factory()->create();

    $this->deleteJson("/api/v1/admin/suppliers/{$supplier->id}")->assertNoContent();
    $this->postJson('/api/v1/admin/purchases', ['supplier_id' => $supplier->id, 'order_date' => '2026-11-12', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '1']]])
        ->assertUnprocessable();
    $this->patchJson("/api/v1/admin/suppliers/{$supplier->id}", ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');
});

it('raises a draft PO with server-computed totals, then places it', function (): void {
    $supplier = Supplier::factory()->create();
    $other = ProductVariant::factory()->create();

    $response = $this->postJson('/api/v1/admin/purchases', [
        'supplier_id' => $supplier->id,
        'order_date' => '2026-11-12',
        'expected_date' => '2026-11-20',
        'lines' => [
            ['variant_id' => $this->variant->id, 'quantity' => 30, 'unit_cost' => '298', 'discount' => '40'],
            ['variant_id' => $other->id, 'quantity' => 3, 'unit_cost' => '333.3333'],
        ],
    ])->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('subtotal', '9940.00')
        ->assertJsonPath('discount', '40.00')
        ->assertJsonPath('total', '9900.00')
        ->assertJsonPath('items.0.line_total', '8900.00')
        ->assertJsonPath('items.1.line_total', '1000.00')
        ->assertJsonPath('can.receive', false)
        ->assertJsonPath('can.place', true);

    expect($response->json('number'))->toMatch('/^PO-\d{4}-\d{4}$/');
    $id = $response->json('id');

    $this->postJson("/api/v1/admin/purchases/{$id}/order")->assertOk()->assertJsonPath('status', 'ordered')->assertJsonPath('can.receive', true);
    $this->postJson("/api/v1/admin/purchases/{$id}/order")->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
    $this->putJson("/api/v1/admin/purchases/{$id}", ['supplier_id' => $supplier->id, 'order_date' => '2026-11-12', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '1']]])
        ->assertStatus(409);
    $this->getJson('/api/v1/admin/purchases/counts')->assertOk()->assertJsonPath('ordered', 1)->assertJsonPath('draft', 0);
});

it('refuses duplicate variants and a discount above the line value', function (): void {
    $supplier = Supplier::factory()->create();
    $line = ['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => '10'];

    $this->postJson('/api/v1/admin/purchases', ['supplier_id' => $supplier->id, 'order_date' => '2026-11-12', 'lines' => [$line, $line]])->assertUnprocessable();
    $this->postJson('/api/v1/admin/purchases', ['supplier_id' => $supplier->id, 'order_date' => '2026-11-12', 'lines' => [[...$line, 'discount' => '10.01']]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'lines.0.discount');
    expect(Purchase::query()->count())->toBe(0);
});

it('filters the PO list by status and supplier, and 400s on anything else', function (): void {
    $a = Supplier::factory()->create(['name' => 'Alpha Toys']);
    Kit::purchase([[$this->variant, 1, '1']], $a);
    Kit::purchase([[$this->variant, 1, '1']], ordered: false);

    $this->getJson('/api/v1/admin/purchases?filter[status]=ordered')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.supplier.name', 'Alpha Toys');
    $this->getJson("/api/v1/admin/purchases?filter[supplier_id]={$a->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/purchases?filter[search]=alpha')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/purchases?filter[total]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/purchases?filter[status]=lost')->assertStatus(400);
    $this->getJson('/api/v1/admin/purchases?sort=total')->assertStatus(400);
});

it('quotes a receipt without writing anything, and refuses to quote a draft', function (): void {
    Kit::open($this->variant->id, 10);
    $po = Kit::purchase([[$this->variant, 30, '298']]);
    $draft = Kit::purchase([[$this->variant, 1, '1']], ordered: false);

    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts/quote", [
        'lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 30]],
        'freight' => '360',
    ])->assertOk()
        ->assertJsonPath('units', 30)
        ->assertJsonPath('goods_value', '8940.00')
        ->assertJsonPath('payable_amount', '9300.00')
        ->assertJsonPath('lines.0.allocated_cost', '360.00')
        ->assertJsonPath('lines.0.landed_unit_cost', '310.0000')
        ->assertJsonPath('lines.0.avg_cost_before', '300.0000')
        ->assertJsonPath('lines.0.avg_cost_after', '307.5000');

    expect(StockMovement::query()->where('type', 'purchase_in')->count())->toBe(0);
    $this->postJson("/api/v1/admin/purchases/{$draft->id}/receipts/quote", ['lines' => [['purchase_item_id' => $draft->items[0]->id, 'quantity' => 1]]])->assertStatus(409);
});

it('receives once per Idempotency-Key, and names the over-received line', function (): void {
    $po = Kit::purchase([[$this->variant, 5, '100']]);
    $key = (string) Str::uuid();
    $body = ['lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 3]], 'freight' => '30', 'apportion_method' => 'quantity'];

    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", $body, ['Idempotency-Key' => $key])
        ->assertCreated()->assertJsonPath('status', 'partially_received')->assertJsonPath('receipts.0.payable_amount', '330.00');
    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", $body, ['Idempotency-Key' => $key])->assertCreated();
    expect($po->items[0]->refresh()->quantity_received)->toBe(3);

    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", $body, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'lines.0.quantity');
    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", $body)->assertStatus(400);
    $this->postJson("/api/v1/admin/purchases/{$po->id}/cancel")->assertStatus(409);
});

it('records and lists supplier payments, newest first', function (): void {
    $supplier = Supplier::factory()->create();
    $po = Kit::purchase([[$this->variant, 2, '100']], $supplier);
    Kit::receive($po, [$po->items[0]->id => 2]);

    $this->postJson("/api/v1/admin/suppliers/{$supplier->id}/payments", ['amount' => '150', 'method' => 'bkash', 'reference' => 'TXN1', 'paid_at' => '2026-11-10', 'purchase_id' => $po->id], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('purchase_number', $po->number);
    $this->postJson("/api/v1/admin/suppliers/{$supplier->id}/payments", ['amount' => '0', 'method' => 'cash', 'paid_at' => '2026-11-10'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable();
    $this->postJson("/api/v1/admin/suppliers/{$supplier->id}/payments", ['amount' => '5', 'method' => 'cash', 'paid_at' => '2099-01-01'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable();

    $this->getJson("/api/v1/admin/suppliers/{$supplier->id}/payments")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '150.00');
    $this->getJson("/api/v1/admin/suppliers/{$supplier->id}/payments?filter[x]=1")->assertStatus(400);
    $this->getJson("/api/v1/admin/suppliers/{$supplier->id}")->assertJsonPath('balance.balance', '50.00');
    $this->getJson("/api/v1/admin/purchases/{$po->id}")->assertJsonPath('due_amount', '50.00');
});
