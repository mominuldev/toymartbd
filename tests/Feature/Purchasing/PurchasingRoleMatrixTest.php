<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Purchasing\PurchasingKit as Kit;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every purchasing endpoint (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->supplier = Supplier::factory()->create();
    $this->variant = ProductVariant::factory()->create();
});

function allowedCount(Permission $permission): int
{
    return collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, $permission))->count();
}

it('guards the supplier reads with purchasing.view', function (string $uri): void {
    RoleMatrix::assert($this, Permission::PurchasingView, fn () => $this->getJson(str_replace('{id}', (string) $this->supplier->id, '/api/v1/admin/'.$uri)));
})->with(['suppliers', 'suppliers/{id}']);

it('guards the purchase reads with purchasing.view', function (string $uri): void {
    $po = Kit::purchase([[$this->variant, 2, '10']], $this->supplier);

    RoleMatrix::assert($this, Permission::PurchasingView, fn () => $this->getJson(str_replace('{id}', (string) $po->id, '/api/v1/admin/'.$uri)));
})->with(['purchases', 'purchases/counts', 'purchases/{id}']);

it('guards supplier writes with purchasing.manage_suppliers', function (): void {
    RoleMatrix::assert($this, Permission::PurchasingManageSuppliers, fn () => $this->postJson('/api/v1/admin/suppliers', ['name' => 'Dhaka Plush House']), 201);
    RoleMatrix::assert($this, Permission::PurchasingManageSuppliers, fn () => $this->patchJson("/api/v1/admin/suppliers/{$this->supplier->id}", ['payment_terms_days' => 15]));
    RoleMatrix::assert($this, Permission::PurchasingManageSuppliers, fn () => $this->deleteJson("/api/v1/admin/suppliers/{$this->supplier->id}"), 204);

    expect(Supplier::query()->where('name', 'Dhaka Plush House')->count())->toBe(allowedCount(Permission::PurchasingManageSuppliers));
});

it('guards PO writes with purchasing.create', function (): void {
    $body = fn (): array => ['supplier_id' => $this->supplier->id, 'order_date' => '2026-11-12', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => '99.50']]];

    RoleMatrix::assert($this, Permission::PurchasingCreate, fn () => $this->postJson('/api/v1/admin/purchases', $body()), 201);
    RoleMatrix::assert($this, Permission::PurchasingCreate, function () use ($body) {
        $draft = Kit::purchase([[$this->variant, 1, '10']], $this->supplier, ordered: false);

        return $this->putJson("/api/v1/admin/purchases/{$draft->id}", $body());
    });
    RoleMatrix::assert($this, Permission::PurchasingCreate, function () {
        $draft = Kit::purchase([[$this->variant, 1, '10']], $this->supplier, ordered: false);

        return $this->postJson("/api/v1/admin/purchases/{$draft->id}/order");
    });
    RoleMatrix::assert($this, Permission::PurchasingCreate, function () {
        $ordered = Kit::purchase([[$this->variant, 1, '10']], $this->supplier);

        return $this->postJson("/api/v1/admin/purchases/{$ordered->id}/cancel");
    });
});

it('guards the receipt quote and the receipt with purchasing.receive', function (): void {
    $po = Kit::purchase([[$this->variant, 50, '10']], $this->supplier);
    $body = ['lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 1]], 'freight' => '5'];

    RoleMatrix::assert($this, Permission::PurchasingReceive, fn () => $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts/quote", $body));
    RoleMatrix::assert($this, Permission::PurchasingReceive, fn () => $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts", $body, ['Idempotency-Key' => (string) Str::uuid()]), 201);

    expect($po->items[0]->refresh()->quantity_received)->toBe(allowedCount(Permission::PurchasingReceive))
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('guards supplier payments: reading with finance.view, recording with finance.record_supplier_payments', function (): void {
    RoleMatrix::assert($this, Permission::FinanceView, fn () => $this->getJson("/api/v1/admin/suppliers/{$this->supplier->id}/payments"));
    RoleMatrix::assert($this, Permission::FinanceRecordSupplierPayments, fn () => $this->postJson(
        "/api/v1/admin/suppliers/{$this->supplier->id}/payments",
        ['amount' => '100', 'method' => 'cash', 'paid_at' => '2026-11-11'],
        ['Idempotency-Key' => (string) Str::uuid()],
    ), 201);

    expect(SupplierPayment::query()->count())->toBe(allowedCount(Permission::FinanceRecordSupplierPayments));
});

it('shows the payable balance only with finance.view, and PO money only with catalog.view_cost', function (): void {
    $po = Kit::purchase([[$this->variant, 2, '10']], $this->supplier);

    foreach ([Role::Accountant, Role::InventoryStaff, Role::Viewer] as $role) {
        RoleMatrix::assertFor($this, $role, true, function () use ($role, $po) {
            $supplier = $this->getJson("/api/v1/admin/suppliers/{$this->supplier->id}");
            $purchase = $this->getJson("/api/v1/admin/purchases/{$po->id}");

            expect($supplier->json('balance') !== null)->toBe(RoleMatrix::allows($role, Permission::FinanceView))
                ->and($purchase->json('total') !== null)->toBe(RoleMatrix::allows($role, Permission::CatalogViewCost));

            return $purchase;
        });
    }
});

it('refuses a guest with 401 on every purchasing endpoint', function (string $method, string $uri): void {
    $po = Kit::purchase([[$this->variant, 2, '10']], $this->supplier);

    $this->json($method, '/api/v1/admin/'.str_replace(['{s}', '{p}'], [(string) $this->supplier->id, (string) $po->id], $uri), [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', 'suppliers'], ['POST', 'suppliers'], ['GET', 'suppliers/{s}'], ['PATCH', 'suppliers/{s}'], ['DELETE', 'suppliers/{s}'],
    ['GET', 'suppliers/{s}/payments'], ['POST', 'suppliers/{s}/payments'],
    ['GET', 'purchases'], ['GET', 'purchases/counts'], ['POST', 'purchases'], ['GET', 'purchases/{p}'], ['PUT', 'purchases/{p}'],
    ['POST', 'purchases/{p}/order'], ['POST', 'purchases/{p}/cancel'], ['POST', 'purchases/{p}/close'], ['POST', 'purchases/{p}/receipts/quote'], ['POST', 'purchases/{p}/receipts'],
]);
