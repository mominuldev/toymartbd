<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\SupplierBalances;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingKit as Kit;

/*
 * Owner decision 2026-11-16: a PO's order total is what was ordered (goods). Freight, customs and
 * other costs entered at receipt are shown apart, from the receipts, and are owed to the supplier
 * only when it charged them. Stock, landed cost and the ledger are unchanged; no stored row is
 * rewritten — a PO received under the old rule (extras added to its header) reads the same way.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
    $this->supplier = Supplier::factory()->create(['name' => 'বংশাল কিডস · Bangshal Kids Imports']);
    $this->variant = ProductVariant::factory()->create(['cost_price' => '500.00']);
});

/** What a receipt did to the PO header before the decision: extras added to it, and so to `total`. */
function receivedTheOldWay(Purchase $po, string $freight, string $customs): void
{
    $po->refresh();
    $po->shipping_cost = $po->shipping_cost->plus(App\Support\Money::of($freight));
    $po->customs_duty = $po->customs_duty->plus(App\Support\Money::of($customs));
    $po->total = $po->total->plus(App\Support\Money::of($freight))->plus(App\Support\Money::of($customs));
    $po->save();
}

it('keeps the order total at the goods ordered and lists the extras apart, owed only when the supplier charged them', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    // Supplier charged 100 freight; a C&F agent charged 50 customs on a second delivery.
    Kit::receive($po, [$po->items[0]->id => 4], freight: '100');
    Kit::receive($po->refresh(), [$po->items[0]->id => 2], customs: '50', costsToSupplier: false);

    expect($po->refresh()->total->toDecimal())->toBe('5000.00')
        ->and($po->shipping_cost->toDecimal())->toBe('0.00');

    $this->getJson("/api/v1/admin/purchases/{$po->id}")
        ->assertOk()
        ->assertJsonPath('total', '5000.00')
        ->assertJsonPath('extra_costs', ['freight' => '100.00', 'customs_duty' => '50.00', 'other' => '0.00', 'total' => '150.00', 'charged_by_supplier' => '100.00', 'paid_elsewhere' => '50.00'])
        ->assertJsonPath('received_goods', '3000.00')
        // 6 × 500 received + the 100 the supplier charged; the agent's 50 is never owed to the supplier.
        ->assertJsonPath('payable_total', '3100.00')
        ->assertJsonPath('due_amount', '3100.00');

    $row = collect($this->getJson('/api/v1/admin/purchases')->json('data'))->firstWhere('id', $po->id);
    expect($row['total'])->toBe('5000.00')->and($row['due_amount'])->toBe('3100.00');

    // The supplier's balance: receipts' payable (goods + what it charged) − payments.
    expect(app(SupplierBalances::class)->for($this->supplier)['balance']->toDecimal())->toBe('3100.00');
    $this->getJson("/api/v1/admin/suppliers/{$this->supplier->id}")->assertJsonPath('balance.balance', '3100.00');
});

it('reads a PO received before the decision the same way, without rewriting its row', function (): void {
    $old = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($old, [$old->items[0]->id => 10], freight: '200', customs: '80');
    receivedTheOldWay($old, '200', '80');
    $new = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($new, [$new->items[0]->id => 10], freight: '200', customs: '80');

    // The stored rows differ (the old one carries the extras in its header) …
    expect($old->refresh()->total->toDecimal())->toBe('5280.00')
        ->and($new->refresh()->total->toDecimal())->toBe('5000.00');

    // … but both read alike: order total = goods, extras from the receipts, owed = goods + extras charged.
    foreach ([$old, $new] as $po) {
        $this->getJson("/api/v1/admin/purchases/{$po->id}")
            ->assertOk()
            ->assertJsonPath('total', '5000.00')
            ->assertJsonPath('extra_costs.freight', '200.00')
            ->assertJsonPath('extra_costs.customs_duty', '80.00')
            ->assertJsonPath('extra_costs.charged_by_supplier', '280.00')
            ->assertJsonPath('payable_total', '5280.00')
            ->assertJsonPath('due_amount', '5280.00');
    }
    expect($old->refresh()->total->toDecimal())->toBe('5280.00');

    // Both open for the KPI (reopen them): the open value is goods only, old row included.
    Purchase::query()->whereKey([$old->id, $new->id])->update(['status' => 'partially_received']);
    $this->getJson('/api/v1/admin/purchases/summary')->assertJsonPath('open_value', '10000.00');

    expect(app(SupplierBalances::class)->for($this->supplier)['balance']->toDecimal())->toBe('10560.00')
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('still puts every extra cost into the landed cost, whoever charged it', function (): void {
    $po = Kit::purchase([[$this->variant, 10, '500']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 10], customs: '100', costsToSupplier: false);

    // 5,000 + 100 over 10 units: 510 each.
    expect($po->refresh()->load('items')->items[0]->landed_unit_cost)->toBe('510.0000');
});
