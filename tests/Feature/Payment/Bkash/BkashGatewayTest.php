<?php

declare(strict_types=1);

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Gateways\Bkash\BkashCredentials;
use App\Domain\Payment\Gateways\Bkash\BkashGateway;
use App\Domain\Payment\Gateways\Bkash\BkashTokenStore;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Payment\Bkash\Fixtures;

/*
 * The bKash driver on its own: configuration per mode (live needs the unpublished live base
 * URL), the token cache and bKash's refresh limit, a POS counter bKash payment never treated
 * as an online one, refunds (and the refund-status fallback on a timeout), Check connection,
 * and credentials never logged. Fixtures are HAND-AUTHORED; the network is never called.
 */

beforeEach(function (): void {
    Fixtures::configure();
    $this->bkash = Fixtures::sandbox();
    $this->gateway = app(BkashGateway::class);
});

/** An order with a bKash payment opened by the driver (initiated), and its context. */
function bkGatewayPayment(string $amount = '1250.00'): Payment
{
    $order = Order::factory()->create(['payment_method' => 'bkash', 'grand_total' => $amount]);

    return DB::transaction(fn (): Payment => app(PaymentTransitions::class)->open($order->id, PaymentMethod::Bkash, Money::of($amount)));
}

function bkInitiate(Payment $payment): Payment
{
    $result = test()->gateway->initiate($payment, new PaymentContext('TMB-2611-0001', 'Rahim', '01712345678', successUrl: 'https://shop.example.test/api/payments/bkash/TMB-2611-0001/success?locale=en'));
    DB::transaction(fn () => app(PaymentTransitions::class)->apply($payment, $result));

    return $payment->refresh();
}

function bkPaid(Payment $payment): Payment
{
    test()->bkash->complete((string) $payment->transaction_id);
    DB::transaction(fn () => app(PaymentTransitions::class)->apply($payment, test()->gateway->verify($payment)));

    return $payment->refresh();
}

it('is configured from the sandbox env keys and drops the query string from callbackURL', function (): void {
    expect($this->gateway->isConfigured())->toBeTrue()->and($this->gateway->isSandbox())->toBeTrue();
    $payment = bkInitiate(bkGatewayPayment());

    expect($payment->status)->toBe(PaymentStatus::Initiated);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment/create')
        && $r['callbackURL'] === 'https://shop.example.test/api/payments/bkash/TMB-2611-0001/success'
        && $r['amount'] === '1250.00');
});

it('is not configured in live mode until bKash\'s live base URL is set, and never falls back to env keys', function (): void {
    $row = PaymentProvider::query()->where('code', 'bkash')->firstOrFail();
    $row->forceFill(['sandbox' => false])->save();
    expect($this->gateway->isConfigured())->toBeFalse();

    config(['payments.bkash.hosts.live' => Fixtures::LIVE]);
    expect($this->gateway->isConfigured())->toBeFalse(); // env keys fill sandbox only

    Fixtures::goLive();
    expect($this->gateway->isConfigured())->toBeTrue()
        ->and(BkashCredentials::resolve()?->baseUrl)->toBe(Fixtures::LIVE)
        ->and(app(OnlineGateways::class)->status(PaymentMethod::Bkash)['sandbox'])->toBeFalse();
});

it('reuses the token, refreshes it when it expires, and falls back to a grant after two refreshes an hour', function (): void {
    $store = app(BkashTokenStore::class);
    $credentials = BkashCredentials::resolve();
    assert($credentials !== null);

    expect($store->token($credentials))->toBe('tok-grant-1')
        ->and($store->token($credentials))->toBe('tok-grant-1');

    // Renewed 10 minutes before bKash's 60-minute expiry: at minute 50–55, as bKash advises.
    $this->travel(49)->minutes();
    expect($store->token($credentials))->toBe('tok-grant-1');
    $this->travel(2)->minutes();
    expect($store->token($credentials))->toBe('tok-refresh-1');
    $this->travel(51)->minutes();
    expect($store->token($credentials))->toBe('tok-refresh-2');
    expect($this->bkash->calls['grant'])->toBe(1);
});

it('never refreshes more than twice within an hour', function (): void {
    $store = app(BkashTokenStore::class);
    $credentials = BkashCredentials::resolve();
    assert($credentials !== null);
    $store->token($credentials);

    foreach (range(1, 4) as $_) {
        $store->forget($credentials);
        expect($store->token($credentials))->not->toBeNull();
    }

    expect($this->bkash->calls['refresh'])->toBe(2)->and($this->bkash->calls['grant'])->toBe(3);
});

it('keeps the cached token encrypted and keyed without any secret', function (): void {
    $credentials = BkashCredentials::resolve();
    assert($credentials !== null);
    app(BkashTokenStore::class)->token($credentials);

    $key = 'payments:bkash:token:'.$credentials->cacheId();
    $raw = (string) cache()->get($key);
    expect($raw)->not->toBe('')->not->toContain('tok-grant-1')
        ->and($key)->not->toContain(Fixtures::APP_KEY)->not->toContain(Fixtures::USERNAME)
        ->and(print_r($credentials, true))->not->toContain(Fixtures::APP_SECRET)->not->toContain(Fixtures::PASSWORD);
});

it('cannot open a payment when bKash refuses the keys', function (): void {
    $this->bkash->grant = 'refused';

    $payment = bkInitiate(bkGatewayPayment());

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('token_unavailable')
        ->and($this->bkash->calls['create'])->toBe(0);
});

it('never queries, executes or refunds a POS counter bKash payment (same method, no driver marker)', function (): void {
    $payment = bkGatewayPayment();
    DB::transaction(fn () => app(PaymentTransitions::class)->apply($payment, new App\Domain\Payment\DTOs\GatewayResult(PaymentStatus::Paid, Money::of('1250.00'), transactionId: 'BK8X2K9QP1')));
    $payment->refresh();

    expect($this->gateway->verify($payment)->payload['unchanged'])->toBe('not_queryable')
        ->and($this->gateway->execute($payment)['code'])->toBe('not_executable')
        ->and($this->gateway->refund($payment, Money::of('100.00'), 'faulty')->status)->toBe(RefundStatus::Failed);
    Http::assertNothingSent();
});

it('refunds a paid payment through the v2 refund call', function (): void {
    $payment = bkPaid(bkInitiate(bkGatewayPayment()));

    $result = $this->gateway->refund($payment, Money::of('250.00'), 'The product is faulty');

    expect($result->status)->toBe(RefundStatus::Completed)->and($result->gatewayReference)->toStartWith('RFD');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/refund/payment/transaction')
        && $r['paymentId'] === $payment->transaction_id
        && $r['trxId'] === $payment->gateway_reference
        && $r['refundAmount'] === '250.00'
        && $r['sku'] === $payment->gateway_payload['merchant_invoice']);
});

it('settles a refund timeout from Refund Status, and fails a refused refund', function (): void {
    $payment = bkPaid(bkInitiate(bkGatewayPayment()));
    $this->bkash->refund = 'timeout';
    $this->bkash->lastRefundAmount = '300.00';

    $result = $this->gateway->refund($payment, Money::of('300.00'), 'not received');
    expect($result->status)->toBe(RefundStatus::Completed)->and($this->bkash->calls['refund_status'])->toBe(1);

    // A completed refund of a different amount is not this one.
    $other = $this->gateway->refund($payment, Money::of('120.00'), 'not received');
    expect($other->status)->toBe(RefundStatus::Failed);

    $this->bkash->refund = 'refused';
    expect($this->gateway->refund($payment, Money::of('50.00'), 'x')->payload['error_code'])->toBe('2071');
});

it('checks the connection with a grant token in either mode', function (): void {
    expect($this->gateway->checkConnection())->toBe(['ok' => true, 'code' => 'ok']);

    $this->bkash->grant = 'refused';
    expect($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'credentials_rejected']);

    $this->bkash->grant = 'down';
    expect($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'unreachable']);

    Fixtures::goLive();
    $live = Fixtures::sandbox(Fixtures::LIVE);
    expect($this->gateway->checkConnection())->toBe(['ok' => true, 'code' => 'ok'])->and($live->calls['grant'])->toBe(1);

    config(['payments.bkash.hosts.live' => null]);
    expect($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'not_configured']);
});

it('sends a Bearer token only when configured to', function (): void {
    config(['payments.bkash.authorization' => 'bearer']);
    bkInitiate(bkGatewayPayment());

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment/create') && $r->hasHeader('Authorization', 'Bearer tok-grant-1'));
});

it('never logs a credential or a token, even when bKash refuses the keys', function (): void {
    Log::spy();
    $this->bkash->grant = 'refused';
    bkInitiate(bkGatewayPayment());

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $flat = $message.json_encode($context);

        return ! str_contains($flat, Fixtures::APP_SECRET) && ! str_contains($flat, Fixtures::PASSWORD) && ! str_contains($flat, Fixtures::APP_KEY);
    });
});
