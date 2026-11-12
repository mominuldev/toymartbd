<?php

declare(strict_types=1);

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzGateway;
use App\Domain\Payment\Gateways\Sslcommerz\SslcommerzSignature;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\OnlineGateways;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Payment\Sslcommerz\Fixtures;

/*
 * P4-PAY-02 — the SSLCommerz v4 driver against HAND-AUTHORED fixtures (see Fixtures.php):
 * session create, validation by val_id, query by tran_id, refund, and the IPN signature.
 * Success, failure, timeout and malformed answers for each. The network is never called.
 */

beforeEach(function (): void {
    Fixtures::configure();
    $this->gateway = app(SslcommerzGateway::class);
    $order = Order::factory()->create(['payment_method' => 'sslcommerz', 'grand_total' => '1930.00']);
    $this->payment = Payment::factory()->create([
        'order_id' => $order->id, 'method' => PaymentMethod::Sslcommerz, 'gateway' => 'sslcommerz', 'amount' => '1930.00', 'status' => PaymentStatus::Pending,
    ]);
    $this->context = new PaymentContext(
        orderNumber: $order->number,
        customerName: 'রাহেলা খাতুন',
        customerPhone: '+8801712345678',
        successUrl: 'https://shop.example.test/api/payments/sslcommerz/'.$order->number.'/success?locale=bn',
        failureUrl: 'https://shop.example.test/api/payments/sslcommerz/'.$order->number.'/fail?locale=bn',
        cancelUrl: 'https://shop.example.test/api/payments/sslcommerz/'.$order->number.'/cancel?locale=bn',
        city: 'Chattogram',
    );
});

function sslPaid(Payment $payment): Payment
{
    $payment->forceFill(['transaction_id' => 'TMB-26-00001-P'.$payment->id, 'status' => PaymentStatus::Initiated])->save();

    return $payment->refresh();
}

it('opens a hosted session: initiated, tran_id per attempt, session key and GatewayPageURL', function (): void {
    Http::fake([Fixtures::SANDBOX.'/gwprocess/v4/api.php' => Http::response(Fixtures::load('session.success'))]);

    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Initiated)
        ->and($result->transactionId)->toBe($this->context->orderNumber.'-P'.$this->payment->id)
        ->and($result->gatewayReference)->toBe('A1B2C3D4E5F60718293A4B5C6D7E8F90')
        ->and($result->redirectUrl)->toStartWith('https://sandbox.sslcommerz.com/gwprocess/v4/gw.php');

    // The stored payload never carries a credential, nor the gateway's marketing blobs.
    expect(json_encode($result->payload))->not->toContain(Fixtures::STORE_PASSWORD)->not->toContain('storeBanner');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request['store_id'] === Fixtures::STORE_ID
            && $request['total_amount'] === '1930.00'
            && $request['currency'] === 'BDT'
            && $request['tran_id'] === $this->context->orderNumber.'-P'.$this->payment->id
            && $request['shipping_method'] === 'NO'
            && str_ends_with((string) $request['success_url'], '/success?locale=bn')
            && str_ends_with((string) $request['cancel_url'], '/cancel?locale=bn');
    });
});

it('fails the attempt when SSLCommerz refuses the session', function (): void {
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response(Fixtures::load('session.failed'))]);

    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('gateway_refused')
        ->and($result->redirectUrl)->toBeNull();
});

it('fails the attempt on a timeout, logging only the exception class (the URL carries store_passwd)', function (): void {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://sandbox.sslcommerz.com/x?store_passwd='.Fixtures::STORE_PASSWORD));

    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('gateway_unreachable')
        ->and(json_encode($result->payload))->not->toContain(Fixtures::STORE_PASSWORD);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c): bool => $m === 'payment.sslcommerz.transport_error'
        && ! str_contains((string) json_encode($c), Fixtures::STORE_PASSWORD))->once();
});

it('fails the attempt on a malformed answer: HTML, a 500, SUCCESS without a URL, a non-https URL', function (mixed $body, int $status): void {
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response($body, $status)]);

    $result = $this->gateway->initiate($this->payment, $this->context);

    expect($result->status)->toBe(PaymentStatus::Failed)->and($result->failureReason)->toBe('malformed_response');
})->with([
    'html page' => ['<html><body>Maintenance</body></html>', 200],
    'server error' => ['{"status":"SUCCESS"}', 500],
    'no url' => [['status' => 'SUCCESS', 'sessionkey' => 'X', 'GatewayPageURL' => ''], 200],
    'plain http url' => [['status' => 'SUCCESS', 'sessionkey' => 'X', 'GatewayPageURL' => 'http://evil.example/pay'], 200],
    'json list' => [[1, 2, 3], 200],
]);

it('validates a val_id: VALID with exact amount and currency is paid, with the fee from store_amount', function (): void {
    $payment = sslPaid($this->payment);
    $values = Fixtures::values($payment);
    Http::fake([Fixtures::SANDBOX.'/validator/api/validationserverAPI.php*' => Http::response(Fixtures::load('validation.valid', $values))]);

    $result = $this->gateway->validate($payment, $values['val_id']);

    expect($result->status)->toBe(PaymentStatus::Paid)
        ->and($result->amount?->toDecimal())->toBe('1930.00')
        ->and($result->fee?->toDecimal())->toBe('48.25')
        ->and($result->gatewayReference)->toBe('2611121600301ABCDEF')
        ->and($result->payload['val_id'])->toBe($values['val_id'])
        ->and($result->payload)->not->toHaveKey('card_no');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'val_id='.$values['val_id']) && str_contains($r->url(), 'format=json'));
});

it('does not close a session on INVALID_TRANSACTION, a tran_id mismatch, a timeout or garbage', function (Closure $fake): void {
    $payment = sslPaid($this->payment);
    $fake($payment);

    $result = $this->gateway->validate($payment, 'some-val-id');

    expect($result->status)->toBe(PaymentStatus::Initiated)->and($result->amount)->toBeNull();
})->with([
    'invalid transaction' => [fn () => Http::fake([Fixtures::SANDBOX.'/*' => Http::response(Fixtures::load('validation.invalid'))])],
    'someone else\'s tran_id' => [fn (Payment $p) => Http::fake([Fixtures::SANDBOX.'/*' => Http::response(Fixtures::load('validation.valid', ['tran_id' => 'OTHER-1'] + Fixtures::values($p)))])],
    'timeout' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out'))],
    'garbage' => [fn () => Http::fake([Fixtures::SANDBOX.'/*' => Http::response('not json')])],
]);

it('reports paid without an amount when the currency differs (so it is never applied as paid)', function (): void {
    $payment = sslPaid($this->payment);
    $body = Fixtures::load('validation.valid', Fixtures::values($payment));
    $body['currency'] = 'USD';
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response($body)]);

    $result = $this->gateway->validate($payment, 'v');

    expect($result->status)->toBe(PaymentStatus::Paid)->and($result->amount)->toBeNull()->and($result->failureReason)->toBe('currency_mismatch');
});

it('queries by tran_id: VALID → paid, FAILED → failed, PENDING → still initiated, none found / unreachable → unchanged', function (string $fixture, ?PaymentStatus $expected): void {
    $payment = sslPaid($this->payment);
    Http::fake([Fixtures::SANDBOX.'/validator/api/merchantTransIDvalidationAPI.php*' => Http::response(Fixtures::load($fixture, Fixtures::values($payment)))]);

    $result = $this->gateway->verify($payment);

    expect($result->status)->toBe($expected);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'tran_id='.urlencode((string) $payment->transaction_id)));
})->with([
    'valid' => ['query.valid', PaymentStatus::Paid],
    'failed' => ['query.failed', PaymentStatus::Failed],
    'pending' => ['query.pending', PaymentStatus::Initiated],
    'none found' => ['query.empty', PaymentStatus::Initiated],
]);

it('treats an APIConnect other than DONE as no answer', function (): void {
    $payment = sslPaid($this->payment);
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response(['APIConnect' => 'FAILED'])]);

    expect($this->gateway->verify($payment)->status)->toBe(PaymentStatus::Initiated);
});

it('refunds a paid payment by bank_tran_id: `success` is processing (money not yet back), `failed` is failed', function (string $fixture, RefundStatus $expected): void {
    $payment = sslPaid($this->payment);
    $payment->forceFill(['status' => PaymentStatus::Paid, 'gateway_reference' => '2611121600301ABCDEF'])->save();
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response(Fixtures::load($fixture, Fixtures::values($payment)))]);

    $result = $this->gateway->refund($payment, Money::of('500.00'), 'Damaged on arrival — ভাঙা অবস্থায় পৌঁছেছে');

    expect($result->status)->toBe($expected)->and(json_encode($result->payload))->not->toContain(Fixtures::STORE_PASSWORD);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'bank_tran_id=2611121600301ABCDEF')
        && str_contains($r->url(), 'refund_amount=500.00') && str_contains($r->url(), 'refund_trans_id='));
})->with([
    'initiated' => ['refund.initiated', RefundStatus::Processing],
    'refused' => ['refund.failed', RefundStatus::Failed],
]);

it('refuses to refund an unpaid payment without calling SSLCommerz, and reads a refund status', function (): void {
    Http::fake([Fixtures::SANDBOX.'/*' => Http::response(Fixtures::load('refund-query.refunded', ['tran_id' => 'T']))]);

    expect($this->gateway->refund($this->payment, Money::of('1.00'), 'x')->status)->toBe(RefundStatus::Failed);
    Http::assertNothingSent();

    $status = $this->gateway->refundStatus('5f1b2c3d4e5f6');
    expect($status->status)->toBe(RefundStatus::Completed)->and($status->gatewayReference)->toBe('5f1b2c3d4e5f6');
});

describe('signature (the official library\'s md5 verify_sign)', function (): void {
    it('accepts a payload signed with the store password, computed independently here', function (): void {
        $post = ['amount' => '1930.00', 'status' => 'VALID', 'tran_id' => 'TMB-26-00001-P1', 'val_id' => 'V1', 'verify_key' => 'amount,status,tran_id,val_id'];
        // By hand: ksort(fields + store_passwd=md5(password)), key=value&…, md5.
        $string = 'amount=1930.00&status=VALID&store_passwd='.md5(Fixtures::STORE_PASSWORD).'&tran_id=TMB-26-00001-P1&val_id=V1';
        $post['verify_sign'] = md5($string);

        expect(SslcommerzSignature::verify($post, Fixtures::STORE_PASSWORD))->toBeTrue();
    });

    it('rejects a tampered field, a wrong password, a missing verify_key, and verify_sign_sha2 alone', function (): void {
        $good = Fixtures::signedNotification('ipn.valid', ['tran_id' => 'T-1', 'val_id' => 'V', 'amount' => '1930.00', 'store_amount' => '1881.75', 'store_id' => Fixtures::STORE_ID]);

        expect(SslcommerzSignature::verify($good, Fixtures::STORE_PASSWORD))->toBeTrue()
            ->and(SslcommerzSignature::verify(['amount' => '1.00'] + $good, Fixtures::STORE_PASSWORD))->toBeFalse()
            ->and(SslcommerzSignature::verify($good, 'wrong-password'))->toBeFalse()
            ->and(SslcommerzSignature::verify(array_diff_key($good, ['verify_key' => 1]), Fixtures::STORE_PASSWORD))->toBeFalse()
            ->and(SslcommerzSignature::verify(['verify_sign_sha2' => hash('sha256', 'x')] + array_diff_key($good, ['verify_sign' => 1]), Fixtures::STORE_PASSWORD))->toBeFalse();
    });
});

describe('availability (rule 33a spirit)', function (): void {
    it('offers SSLCommerz only when active and configured; in production only when verified and live', function (): void {
        $gateways = app(OnlineGateways::class);
        expect($gateways->isEnabled(PaymentMethod::Sslcommerz))->toBeTrue();

        PaymentProvider::query()->where('code', 'sslcommerz')->update(['is_active' => false]);
        expect($gateways->status(PaymentMethod::Sslcommerz)['reason'])->toBe('inactive');

        PaymentProvider::query()->where('code', 'sslcommerz')->update(['is_active' => true]);
        config(['payments.sslcommerz.store_password' => null]);
        expect($gateways->status(PaymentMethod::Sslcommerz)['reason'])->toBe('not_configured');

        config(['payments.sslcommerz.store_password' => Fixtures::STORE_PASSWORD]);
        app()->detectEnvironment(fn (): string => 'production');
        expect($gateways->status(PaymentMethod::Sslcommerz)['reason'])->toBe('unverified');

        PaymentProvider::query()->where('code', 'sslcommerz')->update(['verified' => true]);
        expect($gateways->status(PaymentMethod::Sslcommerz)['reason'])->toBe('sandbox_in_production');

        PaymentProvider::query()->where('code', 'sslcommerz')->update(['sandbox' => false]);
        config(['payments.sslcommerz.sandbox' => false]);
        expect($gateways->isEnabled(PaymentMethod::Sslcommerz))->toBeTrue();
        app()->detectEnvironment(fn (): string => 'testing');
    });

    it('keeps stored credentials encrypted at rest and out of serialisation', function (): void {
        $row = PaymentProvider::forCode('sslcommerz');
        $row?->forceFill(['credentials' => ['store_id' => 'live-store', 'store_password' => 'live-secret']])->save();

        $raw = (string) DB::table('payment_gateways')->where('code', 'sslcommerz')->value('credentials');
        expect($raw)->not->toContain('live-secret')
            ->and(json_encode(PaymentProvider::forCode('sslcommerz')))->not->toContain('live-secret');
    });
});
