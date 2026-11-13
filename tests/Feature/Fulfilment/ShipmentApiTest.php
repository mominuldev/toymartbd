<?php

declare(strict_types=1);

use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Support\CourierFixtures;
use Tests\Support\RoleMatrix;

/*
 * Admin shipment endpoints (docs/api/shipments.md): request (single + bulk), list, failed
 * queue, detail, retry, cancel — with Idempotency-Key on every write (rule 17) and the 403
 * role matrix (rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->courier = CourierFixtures::install('steadfast');
    $this->variant = OrderFixtures::stockedVariant(20, '600.00', '350.00');
});

function saConfirmed(int $variantId, int $qty = 1): Order
{
    return app(TransitionOrder::class)->handle(OrderFixtures::place([$variantId => $qty]), OrderStatus::Confirmed);
}

it('requests a shipment: 202, booked through the courier queue, replayed on the same key', function (): void {
    Support::actingAs(Role::OrderOperator);
    $order = saConfirmed($this->variant->id);

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", ['note' => 'ফোন করে আসবেন'], ['Idempotency-Key' => 'ship-req-0001'])
        ->assertStatus(202)
        ->assertJsonPath('order.number', $order->number)
        ->assertJsonPath('provider.code', 'steadfast')
        ->assertJsonPath('status', 'booked')
        ->assertJsonPath('charge', '70.00')
        ->assertJsonPath('dispatched', true)
        ->assertJsonMissingPath('phone');

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", ['note' => 'ফোন করে আসবেন'], ['Idempotency-Key' => 'ship-req-0001'])
        ->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", [], ['Idempotency-Key' => 'ship-req-0002'])
        ->assertStatus(409)->assertJsonPath('type', fn (string $t): bool => str_contains($t, 'conflict'));

    expect(Shipment::query()->count())->toBe(1)
        ->and($this->courier->booked)->toHaveCount(1)
        ->and($this->courier->booked[0]->note)->toBe('ফোন করে আসবেন');
});

it('requires an Idempotency-Key on every shipment write', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $order = saConfirmed($this->variant->id);

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments")->assertStatus(400);
    $this->postJson('/api/v1/admin/shipments/bulk', ['order_ids' => [$order->id]])->assertStatus(400);
    expect(Shipment::query()->count())->toBe(0);
});

it('rejects an unknown or inactive provider', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $order = saConfirmed($this->variant->id);

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", ['provider' => 'nope'], ['Idempotency-Key' => 'ship-bad-0001'])->assertStatus(422);
    CourierFixtures::provider('pathao', ['is_active' => false, 'is_default' => false]);
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", ['provider' => 'pathao'], ['Idempotency-Key' => 'ship-bad-0002'])->assertStatus(422);
});

it('bulk-requests with per-order results and leaves parcels submitted', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = saConfirmed($this->variant->id);
    $b = saConfirmed($this->variant->id, 2);
    $pending = OrderFixtures::place([$this->variant->id => 1]);

    $this->postJson('/api/v1/admin/shipments/bulk', ['order_ids' => [$a->id, $b->id, $pending->id, 999999]], ['Idempotency-Key' => 'ship-bulk-0001'])
        ->assertOk()
        ->assertJsonPath('meta.requested', 2)
        ->assertJsonPath('meta.failed', 2)
        ->assertJsonPath('data.0.result', 'requested')
        ->assertJsonPath('data.2.result', 'conflict')
        ->assertJsonPath('data.3.result', 'not_found');

    $this->getJson('/api/v1/admin/shipments?filter[status]=submitted')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.consignment_id', null);
});

it('lists the failed queue, retries and cancels', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $order = saConfirmed($this->variant->id);
    $this->courier->willBook(BookingResult::refused('Invalid address'));
    $id = $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", [], ['Idempotency-Key' => 'ship-fail-0001'])
        ->assertStatus(202)->assertJsonPath('status', 'failed')->json('id');

    $this->getJson('/api/v1/admin/shipments/failed')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.failure_code', 'provider_refused')
        ->assertJsonPath('data.0.error_message', 'Invalid address')
        ->assertJsonPath('data.0.needs_attention', true);
    $this->getJson('/api/v1/admin/shipments?filter[needs_attention]=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/admin/shipments/{$id}")->assertOk()->assertJsonPath('status', 'failed');

    $this->postJson("/api/v1/admin/shipments/{$id}/retry", [], ['Idempotency-Key' => 'ship-retry-0001'])
        ->assertOk()->assertJsonPath('status', 'booked');
    $this->getJson('/api/v1/admin/shipments/failed')->assertOk()->assertJsonCount(0, 'data');

    // A booked parcel is not cancellable here.
    $this->postJson("/api/v1/admin/shipments/{$id}/cancel", [], ['Idempotency-Key' => 'ship-cancel-0001'])->assertStatus(409);
    $this->postJson("/api/v1/admin/shipments/{$id}/retry", [], ['Idempotency-Key' => 'ship-retry-0002'])->assertStatus(409);
});

it('cancels a failed shipment', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $order = saConfirmed($this->variant->id);
    $this->courier->willBook(BookingResult::refused('Invalid address'));
    $id = $this->postJson("/api/v1/admin/orders/{$order->id}/shipments", [], ['Idempotency-Key' => 'ship-fail-0002'])->json('id');

    $this->postJson("/api/v1/admin/shipments/{$id}/cancel", ['reason' => 'ঠিকানা ভুল'], ['Idempotency-Key' => 'ship-cancel-0002'])
        ->assertOk()->assertJsonPath('status', 'cancelled');
});

it('refuses unlisted filters and sorts', function (): void {
    Support::actingAs(Role::SuperAdmin);

    $this->getJson('/api/v1/admin/shipments?filter[phone]=017')->assertStatus(400);
    $this->getJson('/api/v1/admin/shipments?sort=charge')->assertStatus(400);
    $this->getJson('/api/v1/admin/shipments?filter[status]=lost')->assertStatus(400);
    $this->getJson('/api/v1/admin/shipments?sort=-booked_at&filter[provider]=steadfast')->assertOk();
});

it('guards GET /shipments with shipments.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/shipments'));
})->with(RoleMatrix::dataset(Permission::ShipmentsView));

it('guards GET /shipments/failed with shipments.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/shipments/failed'));
})->with(RoleMatrix::dataset(Permission::ShipmentsView));

it('guards requesting a shipment with orders.update + shipments.book', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::OrdersUpdate) && RoleMatrix::allows($role, Permission::ShipmentsBook);
    $order = saConfirmed($this->variant->id);

    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        "/api/v1/admin/orders/{$order->id}/shipments", [], ['Idempotency-Key' => 'ship-role-'.$role->value],
    ), 202);
})->with(array_map(fn (Role $r): array => [$r], Role::cases()));

it('guards bulk requests and retry with orders.update + shipments.book', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::OrdersUpdate) && RoleMatrix::allows($role, Permission::ShipmentsBook);
    $order = saConfirmed($this->variant->id);

    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        '/api/v1/admin/shipments/bulk', ['order_ids' => [$order->id]], ['Idempotency-Key' => 'bulk-role-'.$role->value],
    ));

    $this->courier->willBook(BookingResult::refused('x'));
    $other = saConfirmed($this->variant->id);
    $shipment = app(App\Domain\Fulfilment\Actions\RequestShipment::class)->handle($other->id);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        "/api/v1/admin/shipments/{$shipment->id}/retry", [], ['Idempotency-Key' => 'retry-role-'.$role->value],
    ));
})->with(array_map(fn (Role $r): array => [$r], Role::cases()));

it('guards cancel with shipments.cancel', function (Role $role, bool $allowed): void {
    $order = saConfirmed($this->variant->id);
    $shipment = app(App\Domain\Fulfilment\Actions\RequestShipment::class)->handle($order->id, dispatch: false);

    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(
        "/api/v1/admin/shipments/{$shipment->id}/cancel", [], ['Idempotency-Key' => 'cancel-role-'.$role->value],
    ));
})->with(RoleMatrix::dataset(Permission::ShipmentsCancel));

it('is unauthenticated without a session', function (): void {
    $this->getJson('/api/v1/admin/shipments')->assertStatus(401);
    expect(User::query()->count())->toBeGreaterThanOrEqual(0);
});
