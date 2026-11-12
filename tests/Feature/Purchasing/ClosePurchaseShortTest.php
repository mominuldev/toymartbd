<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Purchasing\Actions\ClosePurchaseShort;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\PurchaseClosedShort;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Services\SupplierBalances;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingKit as Kit;
use Tests\Support\RoleMatrix;

/*
 * Closing a partly received PO short (owner decision 2026-11-13): terminal, refuses
 * further receipts, and the supplier is owed only for what arrived.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->supplier = Supplier::factory()->create(['name' => 'Dhaka Plush House · ঢাকা প্লাশ হাউস']);
    $this->variant = ProductVariant::factory()->create();
});

it('closes a partly received PO short with who, when and why; payables stay what arrived', function (): void {
    Event::fake([PurchaseClosedShort::class]);
    $user = User::factory()->create();
    $po = Kit::purchase([[$this->variant, 12, '100']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 10]);

    $closed = app(ClosePurchaseShort::class)->handle($po, 'Supplier is out of the last 2 dolls', $user->id);

    expect($closed->status)->toBe(PurchaseStatus::ClosedShort)
        ->and($closed->closed_by)->toBe($user->id)
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->close_reason)->toBe('Supplier is out of the last 2 dolls')
        ->and($closed->received_value->toDecimal())->toBe('1000.00')
        ->and($closed->due_amount->toDecimal())->toBe('1000.00')
        ->and(app(SupplierBalances::class)->for($this->supplier)['balance']->toDecimal())->toBe('1000.00')
        ->and(Kit::level($this->variant->id)->on_hand)->toBe(10)
        ->and(Kit::ledgerBalanced())->toBeTrue();
    Event::assertDispatched(PurchaseClosedShort::class, fn (PurchaseClosedShort $e): bool => $e->purchaseId === $po->id && $e->unitsNotReceived === 2);
});

it('refuses any further receipt once closed short', function (): void {
    $po = Kit::purchase([[$this->variant, 12, '100']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 10]);
    app(ClosePurchaseShort::class)->handle($po, 'No more stock');

    Kit::receive($po->refresh(), [$po->items[0]->id => 2]);
})->throws(PurchaseConflict::class);

it('closes only a partially received PO', function (string $state): void {
    $po = Kit::purchase([[$this->variant, 2, '100']], $this->supplier, ordered: $state !== 'draft');
    if ($state === 'received') {
        Kit::receive($po, [$po->items[0]->id => 2]);
    }

    app(ClosePurchaseShort::class)->handle($po->refresh(), 'Nothing more coming');
})->throws(PurchaseConflict::class)->with(['draft', 'ordered', 'received']);

it('closes through the API with a reason, lists it under its own status, and 409s on a second close', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    $po = Kit::purchase([[$this->variant, 5, '100']], $this->supplier);
    Kit::receive($po, [$po->items[0]->id => 3]);

    $this->postJson("/api/v1/admin/purchases/{$po->id}/close", [])->assertUnprocessable();
    $this->postJson("/api/v1/admin/purchases/{$po->id}/close", ['reason' => 'Discontinued by the maker'])
        ->assertOk()
        ->assertJsonPath('status', 'closed_short')
        ->assertJsonPath('close_reason', 'Discontinued by the maker')
        ->assertJsonPath('can.receive', false)
        ->assertJsonPath('can.close', false);
    $this->postJson("/api/v1/admin/purchases/{$po->id}/close", ['reason' => 'again'])->assertStatus(409);
    $this->getJson('/api/v1/admin/purchases?filter[status]=closed_short')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/purchases/counts')->assertJsonPath('closed_short', 1);
    $this->postJson("/api/v1/admin/purchases/{$po->id}/receipts/quote", ['lines' => [['purchase_item_id' => $po->items[0]->id, 'quantity' => 1]]])->assertStatus(409);
});

it('guards POST /purchases/{id}/close with purchasing.create', function (): void {
    RoleMatrix::assert($this, Permission::PurchasingCreate, function () {
        $po = Kit::purchase([[$this->variant, 2, '10']], $this->supplier);
        Kit::receive($po, [$po->items[0]->id => 1]);

        return $this->postJson("/api/v1/admin/purchases/{$po->id}/close", ['reason' => 'Supplier closed']);
    });
});
