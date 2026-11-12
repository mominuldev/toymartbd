<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\VerificationStatus;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderNote;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Support\RoleMatrix;

/*
 * Admin order workflow (P4-ORD-04/05 write side): confirm, hold, release, processing,
 * ready to ship, cancel, internal notes and bulk confirm — each through the Ordering
 * Actions, each guarded by its permission (rule 21 role matrix), 409 on a refused move.
 */

function wfActingAs(Role $role): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

const CONFLICT = 'https://toymartbd.com/problems/conflict';

describe('role matrix', function (): void {
    it('guards each write with its permission', function (string $path, OrderStatus $from, ?Permission $permission, array $body): void {
        RoleMatrix::assert(
            $this,
            $permission,
            function () use ($path, $from, $body) {
                $order = Order::factory()->status($from)->create();

                return $this->postJson("/api/v1/admin/orders/{$order->id}/{$path}", $body);
            },
            $path === 'notes' ? 201 : 200,
        );
    })->with([
        'confirm' => ['confirm', OrderStatus::Pending, Permission::OrdersConfirm, []],
        'hold' => ['hold', OrderStatus::Pending, Permission::OrdersUpdate, ['reason' => 'Customer asked to wait']],
        'release' => ['release', OrderStatus::OnHold, Permission::OrdersUpdate, []],
        'processing' => ['processing', OrderStatus::Confirmed, Permission::OrdersUpdate, []],
        'ready to ship' => ['ready-to-ship', OrderStatus::Confirmed, Permission::OrdersUpdate, []],
        'cancel' => ['cancel', OrderStatus::Pending, Permission::OrdersCancel, ['reason' => 'customer_request']],
        'notes' => ['notes', OrderStatus::Pending, Permission::OrdersUpdate, ['body' => 'Called, will confirm tomorrow']],
    ]);

    it('guards bulk confirm with orders.confirm', function (): void {
        RoleMatrix::assert($this, Permission::OrdersConfirm, function () {
            $order = Order::factory()->create();

            return $this->postJson('/api/v1/admin/orders/bulk/confirm', ['order_ids' => [$order->id]]);
        });
    });

    it('refuses a guest with 401', function (): void {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/admin/orders/{$order->id}/confirm")->assertUnauthorized();
        $this->postJson('/api/v1/admin/orders/bulk/confirm', ['order_ids' => [$order->id]])->assertUnauthorized();
    });
});

it('confirms a pending order through the state machine and records who did it', function (): void {
    $user = wfActingAs(Role::OrderOperator);
    $order = Order::factory()->create();

    $json = $this->postJson("/api/v1/admin/orders/{$order->id}/confirm", ['note' => 'Called — confirmed'])->assertOk()->json();

    expect($json['status'])->toBe('confirmed')
        ->and($json['confirmed_at'])->not->toBeNull()
        ->and($json['available_actions'])->toBe(['hold', 'processing', 'ready_to_ship', 'cancel', 'add_note']);

    $event = OrderEvent::query()->where('order_id', $order->id)->sole();
    expect($event->to_status)->toBe('confirmed')
        ->and($event->user_id)->toBe($user->id)
        ->and($event->description)->toBe('Called — confirmed');
});

it('answers a refused move with a 409 conflict that names both statuses', function (): void {
    wfActingAs(Role::OrderOperator);
    $order = Order::factory()->status(OrderStatus::Confirmed)->create();

    $this->postJson("/api/v1/admin/orders/{$order->id}/confirm")
        ->assertStatus(409)
        ->assertJsonPath('type', CONFLICT)
        ->assertJsonPath('detail', 'Order status cannot move from confirmed to confirmed.');

    $shipped = Order::factory()->status(OrderStatus::Shipped)->create();
    $this->postJson("/api/v1/admin/orders/{$shipped->id}/hold", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('type', CONFLICT);
    $this->postJson("/api/v1/admin/orders/{$order->id}/release")->assertStatus(409);
});

it('holds with a required reason, then releases back to pending', function (): void {
    wfActingAs(Role::Manager);
    $order = Order::factory()->create();

    $this->postJson("/api/v1/admin/orders/{$order->id}/hold", [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'reason');

    $held = $this->postJson("/api/v1/admin/orders/{$order->id}/hold", ['reason' => 'ফোনে পাওয়া যায়নি'])->assertOk()->json();
    expect($held['status'])->toBe('on_hold')
        ->and($held['available_actions'])->toContain('release', 'confirm', 'cancel')
        ->and($held['timeline'][0]['description'])->toBe('ফোনে পাওয়া যায়নি');

    expect($this->postJson("/api/v1/admin/orders/{$order->id}/release")->assertOk()->json('status'))->toBe('pending');
});

it('moves a confirmed order to processing and then ready to ship', function (): void {
    wfActingAs(Role::OrderOperator);
    $order = Order::factory()->status(OrderStatus::Confirmed)->create();

    expect($this->postJson("/api/v1/admin/orders/{$order->id}/processing")->assertOk()->json('status'))->toBe('processing');
    expect($this->postJson("/api/v1/admin/orders/{$order->id}/ready-to-ship")->assertOk()->json('status'))->toBe('ready_to_ship')
        ->and($order->refresh()->packed_at)->not->toBeNull();
});

it('cancels through CancelOrder, releasing the reservation', function (): void {
    wfActingAs(Role::OrderOperator);
    $variant = OrderFixtures::stockedVariant(10);
    $order = OrderFixtures::place([$variant->id => 3]);
    expect(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(3);

    $json = $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'fake_order'])->assertOk()->json();

    expect($json['status'])->toBe('cancelled')
        ->and($json['cancel_reason'])->toBe('fake_order')
        ->and($json['available_actions'])->toBe(['add_note'])
        ->and(StockLevel::query()->where('variant_id', $variant->id)->value('reserved'))->toBe(0);
});

it('validates the cancel reason and needs a note for "other"', function (): void {
    wfActingAs(Role::OrderOperator);
    $order = Order::factory()->create();

    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'changed_mind'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'reason');
    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'other'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'note');
    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'other', 'note' => 'Wrong colour ordered'])->assertOk()->assertJsonPath('cancel_note', 'Wrong colour ordered');
});

it('refuses to cancel a shipped order with 409', function (): void {
    wfActingAs(Role::OrderOperator);
    $order = Order::factory()->status(OrderStatus::Shipped)->create();

    $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'customer_request'])->assertStatus(409)->assertJsonPath('type', CONFLICT);
    expect($order->refresh()->status)->toBe(OrderStatus::Shipped);
});

it('appends internal notes and shows them with the author on the detail', function (): void {
    $user = wfActingAs(Role::OrderOperator);
    $order = Order::factory()->create();

    $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => ''])->assertUnprocessable();
    $note = $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'গ্রাহক শুক্রবারের পরে ডেলিভারি চান'])->assertCreated()->json();

    expect($note['user_name'])->toBe($user->name)->and($note['is_customer_visible'])->toBeFalse();
    $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'Will deliver Saturday', 'is_customer_visible' => true])->assertCreated();

    $detail = $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json();
    expect($detail['notes'])->toHaveCount(2)
        ->and($detail['notes'][0]['body'])->toBe('গ্রাহক শুক্রবারের পরে ডেলিভারি চান')
        ->and($detail['notes'][1]['is_customer_visible'])->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('keeps notes append-only', function (): void {
    $order = Order::factory()->create();
    $note = OrderNote::query()->create(['order_id' => $order->id, 'body' => 'first']);

    expect(fn () => $note->update(['body' => 'changed']))->toThrow(OrderViolation::class)
        ->and(fn () => $note->delete())->toThrow(OrderViolation::class);
});

it('bulk-confirms per order and reports partial failure', function (): void {
    wfActingAs(Role::OrderOperator);
    $pending = Order::factory()->create();
    $held = Order::factory()->status(OrderStatus::OnHold)->create();
    $already = Order::factory()->status(OrderStatus::Confirmed)->create();
    $cancelled = Order::factory()->status(OrderStatus::Cancelled)->create();

    $response = $this->postJson('/api/v1/admin/orders/bulk/confirm', ['order_ids' => [$cancelled->id, $pending->id, 999999, $held->id, $already->id]])->assertOk();

    expect($response->json('meta'))->toBe(['done' => 2, 'failed' => 3])
        ->and($response->json('data.*.outcome'))->toBe(['conflict', 'done', 'not_found', 'done', 'conflict'])
        ->and($response->json('data.0.status'))->toBe('cancelled')
        ->and($response->json('data.0.number'))->toBe($cancelled->number)
        ->and($response->json('data.1.status'))->toBe('confirmed')
        ->and($pending->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($held->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($cancelled->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('validates the bulk selection', function (array $body): void {
    wfActingAs(Role::OrderOperator);

    $this->postJson('/api/v1/admin/orders/bulk/confirm', $body)->assertUnprocessable();
})->with([
    'missing' => [[]],
    'empty' => [['order_ids' => []]],
    'duplicate' => [['order_ids' => [1, 1]]],
    'too many' => [['order_ids' => range(1, 101)]],
]);

it('offers only the actions this role may take', function (): void {
    wfActingAs(Role::Viewer);
    $order = Order::factory()->create();

    expect($this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->json('available_actions'))->toBe([]);
});

it('counts and filters the Verify and Anomalies tabs', function (): void {
    wfActingAs(Role::Viewer);
    Order::factory()->create(['verification_status' => VerificationStatus::Pending]);
    Order::factory()->create();
    $unpaid = Order::factory()->status(OrderStatus::Delivered)->create(['fulfillment_status' => FulfillmentStatus::Fulfilled]);
    $refundOwed = Order::factory()->status(OrderStatus::Cancelled)->create(['payment_status' => OrderPaymentStatus::PartiallyPaid, 'paid_total' => '200.00']);
    Order::factory()->status(OrderStatus::Delivered)->create(['payment_status' => OrderPaymentStatus::Paid, 'fulfillment_status' => FulfillmentStatus::Fulfilled]);

    $all = $this->getJson('/api/v1/admin/orders?filter[status]=pending')->assertOk();
    expect($all->json('meta.counts.verify'))->toBe(1)->and($all->json('meta.counts.anomalies'))->toBe(2)->and($all->json('meta.counts.all'))->toBe(5);

    expect($this->getJson('/api/v1/admin/orders?filter[verification_status]=pending')->assertOk()->json('data'))->toHaveCount(1);

    $anomalies = $this->getJson('/api/v1/admin/orders?filter[anomaly]=1')->assertOk();
    expect($anomalies->json('data.*.id'))->toEqualCanonicalizing([$unpaid->id, $refundOwed->id])
        ->and(collect($anomalies->json('data'))->pluck('anomaly', 'id')->all())->toEqual([$refundOwed->id => 'refund_pending', $unpaid->id => 'delivered_unpaid']);

    // A partial refund on a delivered order is only `notable` (spec A12), never "delivered, not paid".
    $refunded = Order::factory()->status(OrderStatus::Delivered)->create(['payment_status' => OrderPaymentStatus::PartiallyRefunded, 'fulfillment_status' => FulfillmentStatus::Fulfilled]);
    expect($this->getJson('/api/v1/admin/orders?filter[anomaly]=1')->json('data.*.id'))->not->toContain($refunded->id)
        ->and($this->getJson("/api/v1/admin/orders/{$refunded->id}")->assertOk()->json('anomaly'))->toBeNull();

    $this->getJson('/api/v1/admin/orders?filter[anomaly]=yes')->assertStatus(400);
    $this->getJson('/api/v1/admin/orders?filter[verification_status]=maybe')->assertStatus(400);
});
