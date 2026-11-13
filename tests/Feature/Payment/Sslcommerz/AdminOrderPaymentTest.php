<?php

declare(strict_types=1);

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Sslcommerz\Fixtures;
use Tests\Support\RoleMatrix;

/*
 * P4-PAY-03 admin: staff re-check an online payment with the gateway (orders.update, 403
 * for every other role — rule 21), and the order's payment card data (gateway state and
 * transaction ids). Fixtures are HAND-AUTHORED; the network is never hit.
 */

beforeEach(function (): void {
    Fixtures::configure();
    $this->order = Order::factory()->create(['payment_method' => 'sslcommerz', 'grand_total' => '1930.00', 'due_total' => '1930.00']);
    $this->payment = Payment::factory()->create([
        'order_id' => $this->order->id, 'method' => PaymentMethod::Sslcommerz, 'gateway' => 'sslcommerz', 'amount' => '1930.00',
        'status' => PaymentStatus::Initiated, 'transaction_id' => $this->order->number.'-P1', 'gateway_reference' => 'SESSIONKEY',
    ]);
});

function sslVerifyPayment(Order $order, Payment $payment): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/verify", [], ['Idempotency-Key' => (string) Str::uuid()]);
}

it('guards POST /orders/{order}/payments/{payment}/verify with orders.update', function (Role $role, bool $allowed): void {
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('query.pending', Fixtures::values($this->payment)))]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => sslVerifyPayment($this->order, $this->payment));
})->with(RoleMatrix::dataset(Permission::OrdersUpdate));

it('re-queries the gateway and applies the authoritative answer', function (): void {
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('query.valid', Fixtures::values($this->payment)))]);
    RoleMatrix::assertFor($this, Role::SuperAdmin, true, fn () => sslVerifyPayment($this->order, $this->payment)
        ->assertJsonPath('payments.0.status', 'paid')
        ->assertJsonPath('payments.0.transaction_id', $this->order->number.'-P1')
        ->assertJsonPath('payments.0.gateway_reference', '2611121600301ABCDEF')
        ->assertJsonPath('payments.0.val_id', '2611121600451XyZaBcDeFgH')
        ->assertJsonPath('payments.0.gateway.verified', false)
        ->assertJsonPath('payments.0.gateway.sandbox', true));

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Paid);
});

it('refuses to re-check a COD payment, and a payment of another order is a 403', function (): void {
    $cod = Payment::factory()->create(['order_id' => $this->order->id, 'method' => PaymentMethod::Cod, 'amount' => '1930.00']);
    $other = Payment::factory()->create(['order_id' => Order::factory()->create()->id, 'method' => PaymentMethod::Sslcommerz, 'amount' => '10.00']);

    RoleMatrix::assertFor($this, Role::SuperAdmin, true, fn () => sslVerifyPayment($this->order, $cod), 409);
    RoleMatrix::assertFor($this, Role::SuperAdmin, false, fn () => sslVerifyPayment($this->order, $other));
});
