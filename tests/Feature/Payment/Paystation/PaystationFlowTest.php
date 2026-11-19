<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ProcessPaystationReceipt;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Models\WebhookReceipt;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Payment\Paystation\Fixtures;

/*
 * PayStation end to end through the storefront API: checkout, the hosted session, the
 * unsigned browser return and IPN (each only a trigger: re-query → apply), a mismatch
 * flagged for review, failure + retry, an idempotent IPN replay, an unknown invoice refused
 * before any write, and the sweeper re-querying PayStation. Fixtures are HAND-AUTHORED.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
    Fixtures::configure();
    $this->variant = CartTestKit::variant('900.00', 5);
});

/** @return array{0: Order, 1: string} */
function psOrder(int $quantity = 2): array
{
    $cart = CheckoutKit::cart([test()->variant->id => $quantity]);
    test()->withToken(test()->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'paystation']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    return [Order::query()->latest('id')->firstOrFail(), $cart];
}

function psStart(Order $order, string $cart): Illuminate\Testing\TestResponse
{
    Http::fake([Fixtures::SANDBOX.'/initiate-payment' => fn (Request $r) => Http::response(Fixtures::load('initiate.success', [
        'invoice_number' => (string) $r['invoice_number'], 'payment_amount' => (string) $r['payment_amount'], 'host' => Fixtures::SANDBOX,
    ]))]);

    return test()->withToken(test()->token)->postJson("/api/v1/storefront/orders/{$order->number}/payment?locale=bn", [], [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ]);
}

/** @param  array<string, mixed>  $answer */
function psStatus(array $answer): void
{
    Http::fake([Fixtures::SANDBOX.'/transaction-status' => Http::response($answer)]);
}

function psReturn(Order $order, ?string $invoice = null): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/payments/paystation/return', array_filter([
        'order_number' => $order->number,
        'invoice_number' => $invoice,
        'query' => ['status' => 'Successful'],
    ]));
}

function psIpn(array $body): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/webhooks/payments/paystation/ipn', $body);
}

function psStatusCalls(): int
{
    return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/transaction-status'))->count();
}

it('refuses a PayStation checkout while the gateway is not enabled', function (): void {
    PaymentProvider::query()->where('code', 'paystation')->update(['is_active' => false]);

    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertOk()->assertJsonMissing(['method' => 'paystation']);
    $cart = CheckoutKit::cart([$this->variant->id => 1]);
    $this->withToken($this->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'paystation']), [
        CartTestKit::HEADER => $cart, 'Idempotency-Key' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'payment_method');
    expect(Order::query()->count())->toBe(0);
});

it('refuses to start a payment in production while the provider is unverified', function (): void {
    [$order, $cart] = psOrder();
    app()->detectEnvironment(fn (): string => 'production');

    $this->withToken($this->token)->postJson("/api/v1/storefront/orders/{$order->number}/payment", [], [
        CartTestKit::HEADER => $cart, 'Idempotency-Key' => (string) Str::uuid(),
    ])->assertStatus(409)->assertJsonPath('reason', 'gateway_unavailable');
    Http::assertNothingSent();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('lists PayStation, opens a sandbox session, and marks paid only from the re-query on the browser return', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertJsonFragment(['method' => 'paystation', 'online' => true, 'sandbox' => true]);
    [$order, $cart] = psOrder();
    $response = psStart($order, $cart)->assertOk()->assertJsonPath('data.next_action.type', 'redirect');
    expect($response->json('data.next_action.url'))->toStartWith(Fixtures::SANDBOX.'/');
    $payment = Fixtures::latestPayment($order->id);
    expect($payment->transaction_id)->toBe($order->number.'-P'.$payment->id)->and($payment->status)->toBe(PaymentStatus::Initiated);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/initiate-payment')
        && $r['callback_url'] === "https://shop.example.test/api/payments/paystation/{$order->number}/success?locale=bn");

    psStatus(Fixtures::load('status.success', Fixtures::values($payment)));
    psReturn($order)->assertOk()
        ->assertJsonPath('data.order_number', $order->number)
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.order_payment_status', 'paid');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->gateway_reference)->toBe('PS'.$payment->id.'X7K2')
        ->and(psStatusCalls())->toBe(1)
        ->and(json_encode(WebhookReceipt::query()->sole()->payload))->not->toContain('01712345678');
    Support::assertStockInvariants();
    Support::assertOrderFinancials($order->refresh());
});

it('closes the attempt when the re-query says failed, whatever the return claims, then retries without a new reservation', function (): void {
    [$order, $cart] = psOrder(2);
    psStart($order, $cart)->assertOk();
    $first = Fixtures::latestPayment($order->id);
    psStatus(Fixtures::load('status.failed', Fixtures::values($first)));

    psReturn($order)->assertOk()->assertJsonPath('data.payment_status', 'failed');

    psStart($order, $cart)->assertOk();
    $second = Fixtures::latestPayment($order->id);
    expect($second->id)->not->toBe($first->id)
        ->and($first->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and((int) StockLevel::query()->where('variant_id', $this->variant->id)->sum('reserved'))->toBe(2);
    Support::assertStockInvariants();
});

it('leaves the payment open when the re-query says processing', function (): void {
    [$order, $cart] = psOrder();
    psStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    psStatus(Fixtures::load('status.processing', Fixtures::values($payment)));

    psReturn($order)->assertOk()->assertJsonPath('data.payment_status', 'initiated');
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending);
});

it('flags an amount mismatch for review: never paid, order held', function (): void {
    [$order, $cart] = psOrder();
    psStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    psStatus(Fixtures::load('status.success', Fixtures::values($payment, amount: '10.00')));

    psReturn($order)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($payment->review_reason)->toBe('amount_mismatch')
        ->and($order->refresh()->payment_status)->not->toBe(OrderPaymentStatus::Paid)
        ->and($order->status)->toBe(OrderStatus::OnHold);
});

it('treats an IPN as a trigger only: re-queries, applies, and a duplicate IPN is a no-op', function (): void {
    [$order, $cart] = psOrder();
    psStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    $values = Fixtures::values($payment);
    psStatus(Fixtures::load('status.success', $values));
    $ipn = Fixtures::load('ipn.success', $values);

    psIpn($ipn)->assertOk()->assertJson(['received' => true, 'replayed' => false]);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and(WebhookReceipt::query()->sole()->result)->toBe('applied')
        ->and(psStatusCalls())->toBe(1);

    psIpn($ipn)->assertOk()->assertJson(['replayed' => true]);
    expect(psStatusCalls())->toBe(1)->and(WebhookReceipt::query()->count())->toBe(1);
    Support::assertOrderFinancials($order->refresh());
});

it('never trusts the IPN body: a forged "Success" the re-query contradicts writes no money', function (): void {
    [$order, $cart] = psOrder();
    psStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    psStatus(Fixtures::load('status.not_found'));
    Queue::fake();

    psIpn(Fixtures::load('ipn.success', Fixtures::values($payment)))->assertOk();
    Queue::assertPushedOn('critical', ProcessPaystationReceipt::class);
    (new ProcessPaystationReceipt(WebhookReceipt::query()->sole()->id))->handle(app(App\Domain\Payment\Actions\HandlePaystationNotification::class));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and(WebhookReceipt::query()->sole()->result)->toBe('deferred')
        ->and(WebhookReceipt::query()->sole()->processed_at)->toBeNull();
});

it('refuses an IPN or return for an invoice that is not ours before writing anything', function (): void {
    [$order, $cart] = psOrder();
    psStart($order, $cart)->assertOk();

    psIpn(['invoice_number' => 'NOT-OURS-P1', 'trx_status' => 'Success', 'trx_amount' => '1.00'])->assertNotFound();
    psIpn(['trx_status' => 'Success'])->assertStatus(422);
    psReturn($order, invoice: 'NOT-OURS-P1')->assertNotFound();
    test()->withToken(test()->token)->postJson('/api/v1/storefront/payments/paystation/return', ['order_number' => 'TMB-NOPE-1'])->assertNotFound();

    expect(WebhookReceipt::query()->count())->toBe(0)->and(psStatusCalls())->toBe(0);
});

it('refuses the IPN from a source IP outside a configured allowlist', function (): void {
    config(['payments.paystation.ipn_allowed_ips' => ['203.0.113.10']]);

    psIpn(['invoice_number' => 'X-P1'])->assertForbidden();
    expect(WebhookReceipt::query()->count())->toBe(0);
});

it('the expiry sweeper re-queries PayStation: a lost return is found paid, an abandoned one expires', function (): void {
    [$paidOrder, $cartA] = psOrder(1);
    psStart($paidOrder, $cartA)->assertOk();
    $paid = Fixtures::latestPayment($paidOrder->id);
    [$lostOrder, $cartB] = psOrder(1);
    psStart($lostOrder, $cartB)->assertOk();
    $lost = Fixtures::latestPayment($lostOrder->id);

    Http::fake([Fixtures::SANDBOX.'/transaction-status' => fn (Request $r) => Http::response(
        $r['invoice_number'] === $paid->transaction_id ? Fixtures::load('status.success', Fixtures::values($paid)) : Fixtures::load('status.not_found'),
    )]);
    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->assertSuccessful();

    expect($paid->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($lost->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($lostOrder->refresh()->status)->toBe(OrderStatus::Cancelled);
    Support::assertStockInvariants();
});
