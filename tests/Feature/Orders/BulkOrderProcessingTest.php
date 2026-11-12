<?php

declare(strict_types=1);

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * P4-ORD-08 — bulk mark packed and invoice PDFs. Partial failure is reported per order,
 * never all-or-nothing. (Bulk confirm is covered in Ordering/AdminOrderWorkflowTest.)
 */

it('marks confirmed and processing orders packed and reports the rest per order', function (): void {
    $user = Support::actingAs(Role::OrderOperator);
    $confirmed = Order::factory()->status(OrderStatus::Confirmed)->create();
    $processing = Order::factory()->status(OrderStatus::Processing)->create();
    $pending = Order::factory()->create();
    $shipped = Order::factory()->status(OrderStatus::Shipped)->create();

    $json = $this->postJson('/api/v1/admin/orders/bulk/pack', ['order_ids' => [$pending->id, $confirmed->id, 999999, $shipped->id, $processing->id]])
        ->assertOk()->json();

    expect($json['meta'])->toBe(['done' => 2, 'failed' => 3])
        ->and(array_column($json['data'], 'outcome'))->toBe(['conflict', 'done', 'not_found', 'conflict', 'done'])
        ->and($json['data'][0]['status'])->toBe('pending')
        ->and($json['data'][3]['status'])->toBe('shipped')
        ->and($confirmed->refresh()->status)->toBe(OrderStatus::ReadyToShip)
        ->and($confirmed->packed_at)->not->toBeNull()
        ->and($processing->refresh()->status)->toBe(OrderStatus::ReadyToShip)
        ->and($pending->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($confirmed->events()->latest('id')->first()?->user_id)->toBe($user->id);
});

it('renders one invoice PDF for many orders, listing the ids that do not exist', function (): void {
    Support::actingAs(Role::OrderOperator);
    $a = Order::factory()->create();
    $b = Order::factory()->create();

    $response = $this->postJson('/api/v1/admin/orders/bulk/invoices', ['order_ids' => [$a->id, 999999, $b->id]])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Orders-Not-Found'))->toBe('999999')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(substr_count((string) $response->getContent(), '/Type /Page'))->toBeGreaterThanOrEqual(2);

    $this->postJson('/api/v1/admin/orders/bulk/invoices', ['order_ids' => [999998]])->assertNotFound();
});

it('renders a single order invoice in Bangla with the Bengali font embedded', function (): void {
    Support::actingAs(Role::Manager);
    $order = Order::factory()->create(['shipping_name' => 'রাহেলা খাতুন', 'shipping_landmark' => 'জামে মসজিদের পাশে']);

    $response = $this->get("/api/v1/admin/orders/{$order->id}/invoice?locale=bn")->assertOk();
    $pdf = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf)->toContain('HindSiliguri');
});

describe('role matrix', function (): void {
    it('guards bulk pack with orders.update', function (): void {
        RoleMatrix::assert($this, Permission::OrdersUpdate, function () {
            $order = Order::factory()->status(OrderStatus::Confirmed)->create();

            return $this->postJson('/api/v1/admin/orders/bulk/pack', ['order_ids' => [$order->id]]);
        });
    });

    it('guards invoices with orders.view plus customers.view_pii', function (Role $role): void {
        $allowed = RoleMatrix::allows($role, Permission::OrdersView) && RoleMatrix::allows($role, Permission::CustomersViewPii);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get('/api/v1/admin/orders/'.Order::factory()->create()->id.'/invoice', ['Accept' => 'application/json']));
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/orders/bulk/invoices', ['order_ids' => [Order::factory()->create()->id]]));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('refuses a guest with 401', function (): void {
        $this->postJson('/api/v1/admin/orders/bulk/pack', ['order_ids' => [1]])->assertUnauthorized();
        $this->getJson('/api/v1/admin/orders/1/invoice')->assertUnauthorized();
    });
});
