<?php

declare(strict_types=1);

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Gateways\Paystation\PaystationCredentials;
use App\Domain\Payment\Gateways\Paystation\PaystationGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Payment\Paystation\Fixtures;

/*
 * The PayStation driver against HAND-AUTHORED fixtures (Fixtures.php): initiate (host by
 * mode), the transaction-status re-query, connection check, credential redaction, and when
 * the gateway may take money. The network is never called.
 */

beforeEach(function (): void {
    Fixtures::configure();
    $this->gateway = app(PaystationGateway::class);
    $order = Order::factory()->create(['payment_method' => 'paystation', 'grand_total' => '1930.00']);
    $this->payment = Payment::factory()->create([
        'order_id' => $order->id, 'method' => PaymentMethod::Paystation, 'gateway' => 'paystation', 'amount' => '1930.00', 'status' => PaymentStatus::Pending,
    ]);
    $this->context = new PaymentContext(
        orderNumber: $order->number,
        customerName: 'রাহেলা খাতুন',
        customerPhone: '+8801712345678',
        successUrl: 'https://shop.example.test/api/payments/paystation/'.$order->number.'/success?locale=bn',
        city: 'Chattogram',
    );
});

function psInitiated(Payment $payment): Payment
{
    $payment->forceFill(['transaction_id' => 'TMB-26-00001-P'.$payment->id, 'status' => PaymentStatus::Initiated])->save();

    return $payment->refresh();
}

function psFakeInitiate(string $host): void
{
    Http::fake([$host.'/initiate-payment' => fn (Request $r) => Http::response(Fixtures::load('initiate.success', [
        'invoice_number' => (string) $r['invoice_number'], 'payment_amount' => (string) $r['payment_amount'], 'host' => $host,
    ]))]);
}

it('opens a session on the SANDBOX host with the sandbox keys while the row is in sandbox mode', function (): void {
    psFakeInitiate(Fixtures::SANDBOX);

    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Initiated)
        ->and($result->transactionId)->toBe($this->context->orderNumber.'-P'.$this->payment->id)
        ->and($result->redirectUrl)->toStartWith(Fixtures::SANDBOX.'/checkout/')
        ->and($result->payload)->not->toHaveKey('password');
    Http::assertSent(fn (Request $r): bool => $r->url() === Fixtures::SANDBOX.'/initiate-payment'
        && $r['merchantId'] === Fixtures::MERCHANT_ID
        && $r['password'] === Fixtures::PASSWORD
        && $r['payment_amount'] === '1930'
        && $r['pay_with_charge'] === '0'
        && $r['currency'] === 'BDT'
        && $r['callback_url'] === $this->context->successUrl
        && $r['cust_email'] === config('payments.paystation.fallback_email'));
});

it('uses the LIVE host and the stored live keys (never the env sandbox keys) in live mode', function (): void {
    Fixtures::goLive();
    psFakeInitiate(Fixtures::LIVE);

    expect($this->gateway->isSandbox())->toBeFalse();
    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Initiated)->and($result->redirectUrl)->toStartWith(Fixtures::LIVE.'/');
    Http::assertSent(fn (Request $r): bool => $r->url() === Fixtures::LIVE.'/initiate-payment'
        && $r['merchantId'] === Fixtures::LIVE_MERCHANT_ID && $r['password'] === Fixtures::LIVE_PASSWORD);
    Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), Fixtures::SANDBOX));
});

it('is not configured in live mode without stored live keys — env keys fill the sandbox set only', function (): void {
    PaymentProvider::query()->where('code', 'paystation')->update(['sandbox' => false]);

    expect(PaystationCredentials::resolve())->toBeNull()
        ->and($this->gateway->isConfigured())->toBeFalse()
        ->and($this->gateway->initiate($this->payment, $this->context)->failureReason)->toBe('not_configured');
    Http::assertNothingSent();
});

it('fails the attempt on a documented refusal (1008 duplicate invoice), a timeout, or an unreadable body', function (): void {
    Http::fake([Fixtures::SANDBOX.'/initiate-payment' => Http::response(Fixtures::load('initiate.duplicate'))]);
    $refused = $this->gateway->initiate($this->payment, $this->context);
    expect($refused->status)->toBe(PaymentStatus::Failed)->and($refused->failureReason)->toBe('gateway_refused')
        ->and($refused->payload['status_code'])->toBe('1008');

    Http::fake([Fixtures::SANDBOX.'/*' => fn () => throw new ConnectionException('cURL error 28 for '.Fixtures::SANDBOX.'/initiate-payment password='.Fixtures::PASSWORD)]);
    $down = app(PaystationGateway::class)->initiate($this->payment, $this->context);
    expect($down->failureReason)->toBe('gateway_unreachable')
        ->and(json_encode($down->payload))->not->toContain(Fixtures::PASSWORD);
});

it('refuses to open a session for an amount with paisa rather than rounding money', function (): void {
    $this->payment->forceFill(['amount' => '1930.50'])->save();

    $result = $this->gateway->initiate($this->payment->refresh(), $this->context);

    expect($result->status)->toBe(PaymentStatus::Failed)->and($result->failureReason)->toBe('amount_not_whole_taka');
    Http::assertNothingSent();
});

it('verifies by invoice number with the merchantId header: success → paid with trx_id and exact amount', function (): void {
    $payment = psInitiated($this->payment);
    Http::fake([Fixtures::SANDBOX.'/transaction-status' => Http::response(Fixtures::load('status.success', Fixtures::values($payment)))]);

    $result = $this->gateway->verify($payment);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->amount?->equals(Money::of('1930.00')))->toBeTrue()
        ->and($result->gatewayReference)->toBe('PS'.$payment->id.'X7K2')
        ->and($result->payload)->not->toHaveKey('payer_mobile_no');
    Http::assertSent(fn (Request $r): bool => $r->url() === Fixtures::SANDBOX.'/transaction-status'
        && $r->header('merchantId') === [Fixtures::MERCHANT_ID]
        && $r['invoice_number'] === $payment->transaction_id
        && ! isset($r['password']));
});

it('maps failed → failed, and leaves processing / 2001 / unknown statuses / another invoice unchanged', function (string $fixture, ?string $status, PaymentStatus $expected, ?string $unchanged): void {
    $payment = psInitiated($this->payment);
    $answer = Fixtures::load($fixture, Fixtures::values($payment));
    if ($status !== null) {
        $answer['data']['trx_status'] = $status;
    }
    Http::fake([Fixtures::SANDBOX.'/transaction-status' => Http::response($answer)]);

    $result = $this->gateway->verify($payment);

    expect($result->status)->toBe($expected)->and($result->payload['unchanged'] ?? null)->toBe($unchanged);
})->with([
    'failed' => ['status.failed', null, PaymentStatus::Failed, null],
    'processing' => ['status.processing', null, PaymentStatus::Initiated, 'processing'],
    'not found (2001)' => ['status.not_found', null, PaymentStatus::Initiated, 'not_found'],
    'refund (documented, not a payment outcome)' => ['status.success', 'refund', PaymentStatus::Initiated, 'unknown_status'],
    'undocumented value' => ['status.success', 'Canceled', PaymentStatus::Initiated, 'unknown_status'],
]);

it('never reports paid for an answer about another invoice', function (): void {
    $payment = psInitiated($this->payment);
    Http::fake([Fixtures::SANDBOX.'/transaction-status' => Http::response(Fixtures::load('status.success', ['invoice_number' => 'SOMEONE-ELSE'] + Fixtures::values($payment)))]);

    expect($this->gateway->verify($payment)->payload['unchanged'])->toBe('invoice_mismatch');
});

it('changes nothing when PayStation is unreachable or answers garbage', function (): void {
    $payment = psInitiated($this->payment);
    Http::fake([Fixtures::SANDBOX.'/transaction-status' => Http::sequence()->push('<html>oops</html>', 200)->push('', 503)]);

    expect($this->gateway->verify($payment)->payload['unchanged'])->toBe('malformed_response')
        ->and($this->gateway->verify($payment)->payload['unchanged'])->toBe('malformed_response');
});

it('has no documented refund API: refund fails without calling PayStation', function (): void {
    $result = $this->gateway->refund($this->payment, Money::of('100.00'), 'Damaged');

    expect($result->status)->toBe(RefundStatus::Failed)->and($result->payload['error'])->toBe('not_supported');
    Http::assertNothingSent();
});

it('checks the connection for the current mode: ok, credentials_rejected, unreachable, not_configured', function (): void {
    Http::fake([Fixtures::SANDBOX.'/initiate-payment' => Http::sequence()
        ->push(Fixtures::load('initiate.success', ['invoice_number' => 'CHK-1', 'payment_amount' => '1', 'host' => Fixtures::SANDBOX]))
        ->push(['status_code' => '1001', 'status' => 'failed', 'message' => 'Invalid merchant credentials'])
        ->push('', 502)
        ->push('not json', 200),
    ]);

    expect($this->gateway->checkConnection())->toBe(['ok' => true, 'code' => 'ok'])
        ->and($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'credentials_rejected'])
        ->and($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'unreachable'])
        ->and($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'unreachable']);
    Http::assertSent(fn (Request $r): bool => str_starts_with((string) $r['invoice_number'], 'CHK-') && $r['payment_amount'] === '1');

    config(['payments.paystation.merchant_id' => null]);
    expect($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'not_configured']);
});

it('never probes PayStation in live mode: the check is sandbox only (owner decision 2026-11-19)', function (): void {
    Fixtures::goLive();
    Http::fake();

    expect($this->gateway->checkConnection())->toBe(['ok' => false, 'code' => 'sandbox_only']);
    Http::assertNothingSent();
});

it('never puts a credential in logs, results or a dump', function (): void {
    Log::spy();
    Http::fake([Fixtures::SANDBOX.'/*' => fn () => throw new ConnectionException('timeout password='.Fixtures::PASSWORD)]);

    $results = [
        $this->gateway->initiate($this->payment, $this->context),
        $this->gateway->verify(psInitiated($this->payment)),
        $this->gateway->checkConnection(),
    ];

    expect(json_encode($results))->not->toContain(Fixtures::PASSWORD)
        ->and(print_r(PaystationCredentials::resolve(), true))->not->toContain(Fixtures::PASSWORD);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx): bool => ! str_contains(json_encode($ctx), Fixtures::PASSWORD))->atLeast()->once();
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains((string) $m, Fixtures::PASSWORD)), Mockery::any()]);
});

it('is offered only while active and configured — and in production only once verified and live', function (): void {
    $gateways = app(OnlineGateways::class);
    expect($gateways->isEnabled(PaymentMethod::Paystation))->toBeTrue();

    PaymentProvider::query()->where('code', 'paystation')->update(['is_active' => false]);
    expect($gateways->status(PaymentMethod::Paystation)['reason'])->toBe('inactive');
    PaymentProvider::query()->where('code', 'paystation')->update(['is_active' => true]);

    app()->detectEnvironment(fn (): string => 'production');
    expect($gateways->status(PaymentMethod::Paystation)['reason'])->toBe('unverified');
    PaymentProvider::query()->where('code', 'paystation')->update(['verified' => true]);
    expect($gateways->status(PaymentMethod::Paystation)['reason'])->toBe('sandbox_in_production');
    Fixtures::goLive();
    expect($gateways->isEnabled(PaymentMethod::Paystation))->toBeTrue();
    app()->detectEnvironment(fn (): string => 'testing');
});
