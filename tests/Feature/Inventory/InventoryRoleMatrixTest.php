<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin inventory endpoint (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->variant = ProductVariant::factory()->create();
    DB::transaction(fn () => app(StockLedger::class)->recordOne($this->variant->id, StockMovementType::OpeningBalance, 100));
});

it('guards the inventory read endpoints with inventory.view', function (string $uri): void {
    RoleMatrix::assert($this, Permission::InventoryView, fn () => $this->getJson('/api/v1/admin/inventory/'.$uri));
})->with(['summary', 'stock', 'movements', 'movements/reconciliation']);

it('guards POST /inventory/adjustments with inventory.adjust', function (): void {
    RoleMatrix::assert($this, Permission::InventoryAdjust, fn () => $this->postJson(
        '/api/v1/admin/inventory/adjustments',
        ['reason' => 'damage', 'lines' => [['variant_id' => $this->variant->id, 'quantity' => -1]]],
        ['Idempotency-Key' => (string) Str::uuid()],
    ), 201);

    $allowed = collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, Permission::InventoryAdjust))->count();
    expect(StockMovement::query()->where('type', 'damage_out')->count())->toBe($allowed);
});

it('refuses a guest with 401 on every admin inventory endpoint', function (string $method, string $uri): void {
    $this->json($method, '/api/v1/admin/inventory/'.$uri, [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', 'summary'],
    ['GET', 'stock'],
    ['GET', 'movements'],
    ['GET', 'movements/reconciliation'],
    ['POST', 'adjustments'],
]);
