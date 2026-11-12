<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\Enums\InventoryAlertKind;
use App\Domain\Inventory\Enums\InventoryAlertStatus;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for the P5-INV inventory-operations endpoints (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'inventory.adjustment_approval.max_units' => 1]);

    $this->variant = Kit::variant();
    Kit::stock($this->variant->id, 1000);
    $this->ctg = Kit::branch()->id;
});

/** A fresh pending adjustment per request, so every allowed role has one to decide. */
function matrixPending(): int
{
    return app(AdjustStock::class)->handle(new StockAdjustmentData(StockAdjustmentReason::Loss, [new StockAdjustmentLine(test()->variant->id, -2)]))->id;
}

function matrixAlert(): int
{
    return InventoryAlert::query()->create([
        'kind' => InventoryAlertKind::LedgerDrift, 'status' => InventoryAlertStatus::Open,
        'variant_id' => test()->variant->id, 'location_id' => StockLocation::defaultId(),
        'expected' => 3, 'actual' => 2, 'alert_date' => DhakaDate::today(), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ])->id;
}

it('guards the inventory-operations read endpoints with inventory.view', function (string $uri): void {
    $id = matrixPending();
    RoleMatrix::assert($this, Permission::InventoryView, fn () => $this->getJson('/api/v1/admin/inventory/'.str_replace('{id}', (string) $id, $uri)));
})->with(['adjustments', 'adjustments/{id}', 'transfers', 'alerts']);

it('guards approve with inventory.approve_adjustments', function (): void {
    RoleMatrix::assert($this, Permission::InventoryApproveAdjustments, fn () => $this->postJson(
        '/api/v1/admin/inventory/adjustments/'.matrixPending().'/approve', [], ['Idempotency-Key' => (string) Str::uuid()],
    ));
});

it('guards reject with inventory.approve_adjustments', function (): void {
    RoleMatrix::assert($this, Permission::InventoryApproveAdjustments, fn () => $this->postJson(
        '/api/v1/admin/inventory/adjustments/'.matrixPending().'/reject', ['note' => 'Not on the count sheet'],
    ));
});

it('guards POST /inventory/transfers with inventory.transfer', function (): void {
    RoleMatrix::assert($this, Permission::InventoryTransfer, fn () => $this->postJson('/api/v1/admin/inventory/transfers', [
        'from_location_id' => StockLocation::defaultId(),
        'to_location_id' => $this->ctg,
        'lines' => [['variant_id' => $this->variant->id, 'quantity' => 1]],
    ], ['Idempotency-Key' => (string) Str::uuid()]), 201);
    Kit::assertInvariants();
});

it('guards acknowledge with inventory.approve_adjustments', function (): void {
    RoleMatrix::assert($this, Permission::InventoryApproveAdjustments, fn () => $this->postJson(
        '/api/v1/admin/inventory/alerts/'.matrixAlert().'/acknowledge', ['note' => 'checked'],
    ));
});

it('holds the approval permission only for super admin and manager', function (): void {
    $holders = collect(Role::cases())->filter(fn (Role $r): bool => RoleMatrix::allows($r, Permission::InventoryApproveAdjustments))->map->value->values()->all();

    expect($holders)->toBe(['super_admin', 'manager']);
});

it('refuses a guest with 401 on every new endpoint', function (string $method, string $uri): void {
    $this->json($method, '/api/v1/admin/inventory/'.$uri, [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();
})->with([
    ['GET', 'adjustments'], ['GET', 'adjustments/1'], ['POST', 'adjustments/1/approve'], ['POST', 'adjustments/1/reject'],
    ['GET', 'transfers'], ['POST', 'transfers'], ['GET', 'alerts'], ['POST', 'alerts/1/acknowledge'],
]);
