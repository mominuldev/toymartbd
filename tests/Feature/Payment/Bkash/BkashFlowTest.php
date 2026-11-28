<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Models\WebhookReceipt;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Payment\Bkash\BkashSandbox;
use Tests\Feature\Payment\Bkash\Fixtures;

/*
 * bKash Tokenized Checkout end to end through the storefront API: checkout, Create Payment,
 * the unverifiable callback (only a trigger: query → execute on success → query → apply), a
 * tampered amount flagged for review, a refused execute and a cancel closing the attempt, a
 * retry, an idempotent replay, an unknown paymentID refused before any write, an execute
 * timeout settled by query, and the sweeper. Fixtures are HAND-AUTHORED.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
    Fixtures::configure();
    $this->variant = CartTestKit::variant('900.00', 5);
    $this->bkash = Fixtures::sandbox();
});

/** @return array{0: Order, 1: string} */
function bkOrder(int $quantity = 2): array
{
    $cart = CheckoutKit::cart([test()->variant->id => $quantity]);
    test()->withToken(test()->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'bkash']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    return [Order::query()->latest('id')->firstOrFail(), $cart];
}

function bkStart(Order $order, string $cart): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson("/api/v1/storefront/orders/{$order->number}/payment?locale=bn", [], [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ]);
}

/** @param  array<string, string>  $query */
function bkReturn(Order $order, string $paymentId, string $status = 'success', ?string $key = null, array $query = []): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/payments/bkash/return', [
        'order_number' => $order->number,
        'payment_id' => $paymentId,
        'status' => $status,
        'signature' => 'vwJk5FkL4i',
        'query' => $query,
    ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

function bkSandbox(): BkashSandbox
{
    return test()->bkash;
}

it('lists bKash only while enabled, and opens a sandbox session with the documented create request', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertJsonFragment(['method' => 'bkash', 'online' => true, 'sandbox' => true]);
    [$order, $cart] = bkOrder();

    $response = bkStart($order, $cart)->assertOk()->assertJsonPath('data.next_action.type', 'redirect');

    $payment = Fixtures::latestPayment($order->id);
    expect($response->json('data.next_action.url'))->toStartWith('https://sandbox.payment.bkash.com/')
        ->and($payment->status)->toBe(PaymentStatus::Initiated)
        ->and($payment->transaction_id)->toStartWith('TR0011FX')
        ->and($payment->gateway_payload['merchant_invoice'])->toBe($order->number.'-P'.$payment->id);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment/create')
        && $r->hasHeader('X-App-Key', Fixtures::APP_KEY)
        && $r->hasHeader('Authorization', 'tok-grant-1')
        && $r['callbackURL'] === "https://shop.example.test/api/payments/bkash/{$order->number}/success"
        && $r['amount'] === $payment->amount->toDecimal()
        && $r['currency'] === 'BDT' && $r['intent'] === 'sale'
        && $r['merchantInvoiceNumber'] === $order->number.'-P'.$payment->id
        && $r['payerReference'] === $order->number);
    // The customer's phone is never sent to bKash, and no secret lands on the payment.
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->body(), (string) $order->shipping_phone));
    expect(json_encode($payment->gateway_payload))->not->toContain(Fixtures::APP_SECRET)->not->toContain('tok-grant');

    PaymentProvider::query()->where('code', 'bkash')->update(['is_active' => false]);
    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertJsonMissing(['method' => 'bkash']);
});

it('captures on a success callback: query, execute, query again — and marks paid only from the query', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);

    bkReturn($order, (string) $payment->transaction_id)->assertOk()
        ->assertJsonPath('data.order_number', $order->number)
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.order_payment_status', 'paid')
        ->assertJsonPath('data.deferred', false);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->gateway_reference)->toStartWith('DIK')
        ->and($payment->fee_amount->toDecimal())->toBe('0.00')
        ->and(bkSandbox()->calls['execute'])->toBe(1)
        ->and(bkSandbox()->calls['query'])->toBe(2)
        ->and(bkSandbox()->calls['grant'])->toBe(1)
        // The payer's wallet number is never kept.
        ->and(json_encode($payment->gateway_payload))->not->toContain('01770618575')
        ->and(json_encode(WebhookReceipt::query()->sole()->payload))->not->toContain('01770618575')
        ->and(WebhookReceipt::query()->sole()->signature_valid)->toBeFalse()
        ->and(WebhookReceipt::query()->sole()->result)->toBe('applied');
    // Rule 10: a captured payment recognises no revenue; that happens on delivery.
    expect(FinancialTransaction::query()->where('is_recognised_revenue', true)->count())->toBe(0);
    Support::assertStockInvariants();
    Support::assertOrderFinancials($order->refresh());
});

it('is idempotent: a replayed callback (same or new key) executes nothing and queries nothing again', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    $key = (string) Str::uuid();

    bkReturn($order, (string) $payment->transaction_id, key: $key)->assertOk()->assertJsonPath('data.payment_status', 'paid');
    bkReturn($order, (string) $payment->transaction_id, key: $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    bkReturn($order, (string) $payment->transaction_id)->assertOk()->assertJsonPath('data.payment_status', 'paid');

    expect(bkSandbox()->calls['execute'])->toBe(1)
        ->and(bkSandbox()->calls['query'])->toBe(2)
        ->and(WebhookReceipt::query()->count())->toBe(1);
});

it('requires an Idempotency-Key on the capturing callback', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);

    $this->withToken($this->token)->postJson('/api/v1/storefront/payments/bkash/return', [
        'order_number' => $order->number, 'payment_id' => $payment->transaction_id, 'status' => 'success',
    ])->assertStatus(400);
    expect(bkSandbox()->calls['execute'])->toBe(0)->and(WebhookReceipt::query()->count())->toBe(0);
});

it('ignores a tampered amount: the query decides, and a query amount that differs is flagged, never paid', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    bkSandbox()->queryAmount = '10.00';

    // Whatever the forwarded callback claims about money is ignored.
    bkReturn($order, (string) $payment->transaction_id, query: ['amount' => (string) $payment->amount->toDecimal(), 'transactionStatus' => 'Completed'])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($payment->review_reason)->toBe('amount_mismatch')
        ->and($order->refresh()->payment_status)->not->toBe(OrderPaymentStatus::Paid)
        ->and($order->status)->toBe(OrderStatus::OnHold)
        ->and(WebhookReceipt::query()->sole()->result)->toBe('flagged');
});

it('pays from the query even when the forwarded callback claims a different amount', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);

    bkReturn($order, (string) $payment->transaction_id, query: ['amount' => '1.00'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
    expect($payment->refresh()->amount->toDecimal())->toBe($order->refresh()->grand_total->toDecimal())
        ->and($payment->net_amount->toDecimal())->toBe($payment->amount->toDecimal());
});

it('closes the attempt as failed when execute is refused, then retries with a new paymentId', function (): void {
    [$order, $cart] = bkOrder(2);
    bkStart($order, $cart)->assertOk();
    $first = Fixtures::latestPayment($order->id);
    bkSandbox()->execute = 'insufficient_balance';

    bkReturn($order, (string) $first->transaction_id)->assertOk()->assertJsonPath('data.payment_status', 'failed');
    expect($first->refresh()->failure_reason)->toBe('execute_refused:2023');

    bkStart($order, $cart)->assertOk();
    $second = Fixtures::latestPayment($order->id);
    expect($second->id)->not->toBe($first->id)
        ->and($second->transaction_id)->not->toBe($first->transaction_id)
        ->and((int) StockLevel::query()->where('variant_id', $this->variant->id)->sum('reserved'))->toBe(2);
    Support::assertStockInvariants();
});

it('closes a cancelled attempt without executing, but a cancel bKash contradicts is paid from the query', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);

    bkReturn($order, (string) $payment->transaction_id, 'cancel')->assertOk()->assertJsonPath('data.payment_status', 'cancelled');
    expect(bkSandbox()->calls['execute'])->toBe(0)->and($payment->refresh()->failure_reason)->toBe('customer_cancelled');

    // A forged "failure" for a payment bKash reports Completed changes nothing about money.
    bkStart($order, $cart)->assertOk();
    $second = Fixtures::latestPayment($order->id);
    bkSandbox()->complete((string) $second->transaction_id);
    bkReturn($order, (string) $second->transaction_id, 'failure')->assertOk()->assertJsonPath('data.payment_status', 'paid');
    expect(bkSandbox()->calls['execute'])->toBe(0);
});

it('settles an execute timeout by query: deferred while unknown, paid on retry without a second execute', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    bkSandbox()->execute = 'timeout';

    bkReturn($order, (string) $payment->transaction_id)->assertOk()
        ->assertJsonPath('data.payment_status', 'initiated')
        ->assertJsonPath('data.deferred', true);
    expect(WebhookReceipt::query()->sole()->processed_at)->toBeNull();

    // bKash did complete it after all (Execute's answer was lost).
    bkSandbox()->complete((string) $payment->transaction_id);
    bkReturn($order, (string) $payment->transaction_id)->assertOk()->assertJsonPath('data.payment_status', 'paid');
    expect(bkSandbox()->calls['execute'])->toBe(1)->and(WebhookReceipt::query()->sole()->result)->toBe('applied');
});

it('pays when execute times out but bKash completed the payment', function (): void {
    [$order, $cart] = bkOrder();
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    bkSandbox()->execute = 'timeout_but_completed';

    bkReturn($order, (string) $payment->transaction_id)->assertOk()->assertJsonPath('data.payment_status', 'paid');
});

it('refuses a callback for a paymentID that is not ours, or not this order\'s, before writing anything', function (): void {
    [$order, $cart] = bkOrder(1);
    bkStart($order, $cart)->assertOk();
    $payment = Fixtures::latestPayment($order->id);
    [$other] = bkOrder(1);

    bkReturn($order, 'TR0011NOTOURS')->assertNotFound();
    bkReturn($other, (string) $payment->transaction_id)->assertNotFound();
    test()->withToken(test()->token)->postJson('/api/v1/storefront/payments/bkash/return', ['order_number' => 'TMB-NOPE-1', 'payment_id' => 'X1', 'status' => 'success'], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
    bkReturn($order, (string) $payment->transaction_id, 'paid')->assertStatus(422);

    expect(WebhookReceipt::query()->count())->toBe(0)
        ->and(bkSandbox()->calls['query'])->toBe(0)
        ->and(bkSandbox()->calls['execute'])->toBe(0);
});

it('reuses one token across payments and renews it once after a 401', function (): void {
    [$a, $cartA] = bkOrder(1);
    [$b, $cartB] = bkOrder(1);
    bkStart($a, $cartA)->assertOk();
    bkStart($b, $cartB)->assertOk();
    expect(bkSandbox()->calls['grant'])->toBe(1);

    bkSandbox()->unauthorised = 1;
    $payment = Fixtures::latestPayment($b->id);
    bkReturn($b, (string) $payment->transaction_id)->assertOk()->assertJsonPath('data.payment_status', 'paid');
    expect(bkSandbox()->calls['grant'] + bkSandbox()->calls['refresh'])->toBe(2);
});

it('the expiry sweeper re-queries bKash: a lost callback is found paid, an abandoned attempt expires', function (): void {
    [$paidOrder, $cartA] = bkOrder(1);
    bkStart($paidOrder, $cartA)->assertOk();
    $paid = Fixtures::latestPayment($paidOrder->id);
    [$lostOrder, $cartB] = bkOrder(1);
    bkStart($lostOrder, $cartB)->assertOk();
    $lost = Fixtures::latestPayment($lostOrder->id);
    bkSandbox()->complete((string) $paid->transaction_id);

    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->assertSuccessful();

    expect($paid->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($lost->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($lostOrder->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(bkSandbox()->calls['execute'])->toBe(0);
    Support::assertStockInvariants();
});

it('refuses to start a payment in production while bKash is unverified', function (): void {
    [$order, $cart] = bkOrder();
    app()->detectEnvironment(fn (): string => 'production');

    bkStart($order, $cart)->assertStatus(409)->assertJsonPath('reason', 'gateway_unavailable');
    expect(bkSandbox()->calls['create'])->toBe(0);
    app()->detectEnvironment(fn (): string => 'testing');
});
