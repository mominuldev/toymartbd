<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Models\WebhookReceipt;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Payment\Sslcommerz\Fixtures;

/*
 * P4-PAY-02/03/04 end to end through the storefront API: checkout with SSLCommerz, the
 * hosted session, the signed IPN / browser return (signature → receipt → re-query → apply),
 * a mismatch flagged for review, failure + retry without a second reservation, and expiry
 * releasing the stock. Fixtures are HAND-AUTHORED (Fixtures.php); the network is never hit.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = CartTestKit::storefrontToken();
    config(['risk.first_order_hold_threshold' => '100000.00']);
    Fixtures::configure();
    $this->variant = CartTestKit::variant('900.00', 5);
});

/** Place an SSLCommerz order through the API; returns [order, cart token]. */
function sslOnlineOrder(int $quantity = 2): array
{
    $cart = CheckoutKit::cart([test()->variant->id => $quantity]);
    test()->withToken(test()->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'sslcommerz']), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    return [Order::query()->latest('id')->firstOrFail(), $cart];
}

function sslStartPayment(Order $order, string $cart, ?string $key = null): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson("/api/v1/storefront/orders/{$order->number}/payment?locale=bn", [], [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
    ]);
}

function sslFakeSession(): void
{
    Http::fake([Fixtures::SANDBOX.'/gwprocess/v4/api.php' => Http::response(Fixtures::load('session.success'))]);
}

function sslReserved(int $variantId): int
{
    return (int) StockLevel::query()->where('variant_id', $variantId)->sum('reserved');
}

function sslValidatorCalls(): int
{
    return Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/validator/api/'))->count();
}

function sslIpn(array $post): Illuminate\Testing\TestResponse
{
    return test()->post('/api/v1/webhooks/payments/sslcommerz/ipn', $post, ['Accept' => 'application/json']);
}

it('lists only COD while the gateway is inactive, and refuses an SSLCommerz checkout', function (): void {
    PaymentProvider::query()->where('code', 'sslcommerz')->update(['is_active' => false]);

    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertOk()
        ->assertExactJson(['data' => [['method' => 'cod', 'online' => false, 'default' => true, 'sandbox' => false]]]);

    $cart = CheckoutKit::cart([$this->variant->id => 1]);
    $this->withToken($this->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, ['payment_method' => 'sslcommerz']), [
        CartTestKit::HEADER => $cart, 'Idempotency-Key' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'payment_method');
    expect(Order::query()->count())->toBe(0);
});

it('refuses an online payment in production while the provider is unverified (rule 33a spirit)', function (): void {
    [$order, $cart] = sslOnlineOrder();
    app()->detectEnvironment(fn (): string => 'production');

    sslStartPayment($order, $cart)->assertStatus(409)->assertJsonPath('reason', 'gateway_unavailable');
    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertJsonCount(1, 'data');
    Http::assertNothingSent();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('places an online order (stock reserved once), opens a session, and needs an Idempotency-Key', function (): void {
    [$order, $cart] = sslOnlineOrder(2);
    expect(sslReserved($this->variant->id))->toBe(2)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Unpaid);
    $this->withToken($this->token)->getJson('/api/v1/storefront/payment-methods')->assertJsonPath('data.1.method', 'sslcommerz');

    $this->withToken($this->token)->postJson("/api/v1/storefront/orders/{$order->number}/payment", [], [CartTestKit::HEADER => $cart])
        ->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');

    sslFakeSession();
    $response = sslStartPayment($order, $cart)->assertOk()
        ->assertJsonPath('data.next_action.type', 'redirect')
        ->assertJsonPath('data.payment.status', 'initiated');
    expect($response->json('data.next_action.url'))->toStartWith('https://sandbox.sslcommerz.com/');

    $payment = Fixtures::initiatedPayment($order);
    expect($payment->transaction_id)->toBe($order->number.'-P'.$payment->id)
        ->and($payment->expires_at)->not->toBeNull()
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending)
        ->and(sslReserved($this->variant->id))->toBe(2);

    // The confirmation object offers the live session; another cart's token gets a 404.
    $this->withToken($this->token)->getJson("/api/v1/storefront/orders/{$order->number}", [CartTestKit::HEADER => $cart])
        ->assertJsonPath('payment.online', true)
        ->assertJsonPath('payment.next_action.type', 'redirect');
    sslStartPayment($order, (string) Str::uuid())->assertNotFound();
    Support::assertStockInvariants();
});

it('marks the order paid only after a verified IPN AND the validation API agree; a replay is a no-op', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($payment);
    Http::fake([Fixtures::SANDBOX.'/validator/api/validationserverAPI.php*' => Http::response(Fixtures::load('validation.valid', $values))]);

    $post = Fixtures::signedNotification('ipn.valid', $values);
    sslIpn($post)->assertOk()->assertJson(['received' => true, 'replayed' => false]);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->gateway_reference)->toBe('2611121600301ABCDEF')
        ->and($payment->fee_amount->toDecimal())->toBe(Money::of($values['amount'])->minus(Money::of($values['store_amount']))->toDecimal())
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and(WebhookReceipt::query()->sole()->result)->toBe('applied');
    expect(sslValidatorCalls())->toBe(1); // exactly one re-query, for the val_id

    sslIpn($post)->assertOk()->assertJson(['replayed' => true]);
    expect(sslValidatorCalls())->toBe(1);
    expect(WebhookReceipt::query()->count())->toBe(1);
    Support::assertStockInvariants();
    Support::assertOrderFinancials($order);
});

it('writes nothing for a bad signature (401), even when the body claims VALID', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($payment);

    sslIpn(Fixtures::signedNotification('ipn.valid', $values, tamper: ['amount' => '1.00']))->assertStatus(401);
    sslIpn(Fixtures::signedNotification('ipn.valid', $values, password: 'not-our-password'))->assertStatus(401);

    expect(WebhookReceipt::query()->count())->toBe(0)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    expect(sslValidatorCalls())->toBe(0); // never re-queried for a forged body
});

it('flags an amount mismatch from the validation API for review: never paid, order held', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($payment);
    // The signed body says the right amount; the authoritative answer says less.
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('validation.valid', ['amount' => '10.00', 'store_amount' => '9.75'] + $values))]);

    sslIpn(Fixtures::signedNotification('ipn.valid', $values))->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Initiated)
        ->and($payment->review_reason)->toBe('amount_mismatch')
        ->and($order->refresh()->payment_status)->not->toBe(OrderPaymentStatus::Paid)
        ->and($order->status)->toBe(OrderStatus::OnHold)
        ->and(WebhookReceipt::query()->sole()->result)->toBe('flagged');

    // A flagged order is left for a human: no retry, no expiry.
    sslStartPayment($order, $cart)->assertStatus(409)->assertJsonPath('reason', 'under_review');
    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->assertSuccessful();
    expect($order->refresh()->status)->toBe(OrderStatus::OnHold)->and(sslReserved($this->variant->id))->toBe(2);
});

it('flags a currency mismatch the same way', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($payment);
    $answer = Fixtures::load('validation.valid', $values);
    $answer['currency'] = 'USD';
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response($answer)]);

    sslIpn(Fixtures::signedNotification('ipn.valid', $values))->assertOk();

    expect($payment->refresh()->review_reason)->toBe('currency_mismatch')->and($payment->status)->not->toBe(PaymentStatus::Paid);
});

it('closes a failed attempt from a signed FAILED return, then retries with a new session and no new reservation', function (): void {
    [$order, $cart] = sslOnlineOrder(2);
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $first = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($first);
    Http::fake([Fixtures::SANDBOX.'/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(Fixtures::load('query.pending', $values))]);

    // The browser return, forwarded by the storefront server (its token), signed by SSLCommerz.
    $this->withToken($this->token)->post('/api/v1/storefront/payments/sslcommerz/return', Fixtures::signedNotification('ipn.failed', $values), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.order_number', $order->number)
        ->assertJsonPath('data.payment_status', 'failed')
        ->assertJsonPath('data.order_payment_status', 'failed');

    $this->withToken($this->token)->getJson("/api/v1/storefront/orders/{$order->number}", [CartTestKit::HEADER => $cart])
        ->assertJsonPath('payment.status', 'failed')
        ->assertJsonPath('payment.retry_allowed', true)
        ->assertJsonPath('payment.next_action', null);

    sslStartPayment($order, $cart)->assertOk()->assertJsonPath('data.next_action.type', 'redirect');
    $second = Fixtures::initiatedPayment($order);
    expect($second->id)->not->toBe($first->id)
        ->and($second->transaction_id)->toBe($order->number.'-P'.$second->id)
        ->and($first->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and(sslReserved($this->variant->id))->toBe(2)
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending);
    Support::assertStockInvariants();
});

it('resumes the same live session instead of opening a second one (no double charge)', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    $url = sslStartPayment($order, $cart)->json('data.next_action.url');
    $payment = Fixtures::initiatedPayment($order);
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('query.pending', Fixtures::values($payment)))]);

    sslStartPayment($order, $cart)->assertOk()->assertJsonPath('data.resumed', true)->assertJsonPath('data.next_action.url', $url);
    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('expires an abandoned online payment: payment expired, order cancelled, stock released; invariants hold', function (): void {
    [$order, $cart] = sslOnlineOrder(3);
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('query.empty'))]);
    expect(sslReserved($this->variant->id))->toBe(3);

    // Inside the window: nothing happens.
    $this->artisan('toymart:payments:expire')->assertSuccessful();
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);

    $this->travel(config('payments.online.expiry_minutes') + 1)->minutes();
    $this->artisan('toymart:payments:expire')->expectsOutputToContain('Expired 1')->assertSuccessful();

    $order->refresh();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toBe(CancellationReason::PaymentFailed)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Failed)
        ->and(sslReserved($this->variant->id))->toBe(0);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'merchantTransIDvalidationAPI.php')); // asked before giving up
    Support::assertStockInvariants();
    Support::assertOrderFinancials($order);

    // Expired is final for this order: a retry is refused; a second sweep changes nothing.
    sslStartPayment($order, $cart)->assertStatus(409)->assertJsonPath('reason', 'order_closed');
    $this->artisan('toymart:payments:expire')->assertSuccessful();
    expect(sslReserved($this->variant->id))->toBe(0);
    Support::assertStockInvariants();
});

it('expires an order whose only attempt never reached the gateway, and never expires a COD order', function (): void {
    [$online] = sslOnlineOrder(1);
    $codCart = CheckoutKit::cart([$this->variant->id => 1]);
    $this->withToken($this->token)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($codCart), [
        CartTestKit::HEADER => $codCart, 'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();
    $cod = Order::query()->latest('id')->firstOrFail();

    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->assertSuccessful();

    expect($online->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($cod->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(sslReserved($this->variant->id))->toBe(1);
    Http::assertNothingSent();
    Support::assertStockInvariants();
});

it('does not expire an order the gateway reports as paid (lost IPN): it is marked paid instead', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    Http::fake([Fixtures::SANDBOX.'/validator/*' => Http::response(Fixtures::load('query.valid', Fixtures::values($payment)))]);

    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->expectsOutputToContain('found paid 1')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and(sslReserved($this->variant->id))->toBe(2);
    Support::assertStockInvariants();
});

it('flags a payment that arrives after its attempt expired, instead of reopening it', function (): void {
    [$order, $cart] = sslOnlineOrder();
    sslFakeSession();
    sslStartPayment($order, $cart)->assertOk();
    $payment = Fixtures::initiatedPayment($order);
    $values = Fixtures::values($payment);
    Http::fake([Fixtures::SANDBOX.'/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(Fixtures::load('query.empty'))]);
    $this->travel(2)->hours();
    $this->artisan('toymart:payments:expire')->assertSuccessful();

    Http::fake([Fixtures::SANDBOX.'/validator/api/validationserverAPI.php*' => Http::response(Fixtures::load('validation.valid', $values))]);
    sslIpn(Fixtures::signedNotification('ipn.valid', $values))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payment->review_reason)->toBe('paid_after_close')
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->events()->where('type', 'note')->where('description', 'like', '%paid_after_close%')->exists())->toBeTrue();
});

it('refuses an IPN from outside the configured source-IP allowlist before reading it', function (): void {
    config(['payments.sslcommerz.ipn_allowed_ips' => ['103.26.139.87']]);

    sslIpn(['tran_id' => 'x'])->assertStatus(403);
    expect(WebhookReceipt::query()->count())->toBe(0);
});
