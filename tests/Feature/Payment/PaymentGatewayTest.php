<?php

declare(strict_types=1);

use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\CollectCodPayment;
use App\Domain\Payment\Actions\InitiatePayment;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Events\PaymentStatusChanged;
use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\PaymentAmountMismatch;
use App\Domain\Payment\Exceptions\PaymentViolation;
use App\Domain\Payment\Exceptions\UnsupportedPaymentMethod;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Ordering\Support\OrderFixtures;

/*
 * P4-PAY-01 — the PaymentGateway contract, the driver registry and the COD driver.
 * "COD flows through the same status transitions as online": both are driven through
 * PaymentTransitions::apply and the same Ordering listener, and their recorded paths are
 * checked against the one PaymentStatus table.
 */

/** A stand-in online gateway: initiate → initiated + redirect; verify → whatever the provider says. */
final class FakeOnlineGateway implements PaymentGateway
{
    public static ?GatewayResult $next = null;

    public function method(): PaymentMethod
    {
        return PaymentMethod::Bkash;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Initiated, gatewayReference: 'SESSION-1', redirectUrl: 'https://pay.example.test/s/1');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return self::$next ?? new GatewayResult(PaymentStatus::Initiated);
    }

    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        return new RefundResult(RefundStatus::Completed, 'RF-1');
    }
}

function payContext(): PaymentContext
{
    return new PaymentContext('TMB-26-00001', 'আরিফ হোসেন', '+8801712345678');
}

/** @return list<string> "from→to" of every PaymentStatusChanged for this payment */
function payPath(int $paymentId): array
{
    return Event::dispatched(PaymentStatusChanged::class)
        ->map(fn (array $args): PaymentStatusChanged => $args[0])
        ->filter(fn (PaymentStatusChanged $e): bool => $e->paymentId === $paymentId)
        ->map(fn (PaymentStatusChanged $e): string => "{$e->from}→{$e->to}")
        ->values()->all();
}

beforeEach(function (): void {
    FakeOnlineGateway::$next = null;
    app(PaymentGatewayRegistry::class)->register(PaymentMethod::Bkash, FakeOnlineGateway::class);
    $this->variant = OrderFixtures::stockedVariant(20);
});

it('registers COD as a first-class driver and refuses an unregistered method', function (): void {
    $registry = app(PaymentGatewayRegistry::class);

    expect($registry->for(PaymentMethod::Cod))->toBeInstanceOf(CashOnDeliveryGateway::class)
        ->and($registry->supports(PaymentMethod::Cod))->toBeTrue()
        ->and($registry->supports(PaymentMethod::Nagad))->toBeFalse()
        ->and(fn () => $registry->for(PaymentMethod::Nagad))->toThrow(UnsupportedPaymentMethod::class);
});

it('implements exactly the documented payment transition table', function (PaymentStatus $from, PaymentStatus $to): void {
    $table = [
        'pending' => ['initiated', 'authorised', 'paid', 'failed', 'cancelled', 'expired'],
        'initiated' => ['authorised', 'paid', 'failed', 'cancelled', 'expired'],
        'authorised' => ['paid', 'failed', 'cancelled', 'expired'],
        'paid' => [], 'failed' => [], 'cancelled' => [], 'expired' => [],
    ];
    $payment = Payment::factory()->create(['status' => $from, 'amount' => '1320.00']);
    $apply = fn () => DB::transaction(fn () => app(PaymentTransitions::class)
        ->apply($payment, new GatewayResult($to, amount: Money::of('1320.00'))));

    if ($from === $to) {
        expect($apply()->status)->toBe($from); // idempotent no-op

        return;
    }
    if (! in_array($to->value, $table[$from->value], true)) {
        expect($apply)->toThrow(InvalidPaymentTransition::class);
        expect($payment->refresh()->status)->toBe($from);

        return;
    }
    expect($apply()->status)->toBe($to);
})->with(function (): array {
    $pairs = [];
    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            $pairs["{$from->value} → {$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
});

it('takes COD and online through the same transitions to a paid order', function (): void {
    Event::fake([PaymentStatusChanged::class]);

    // COD: opened pending → initiate is a no-op (no redirect) → courier collects → paid.
    $cod = OrderFixtures::place([$this->variant->id => 1]);
    $codPayment = $cod->payments()->sole();
    $codInit = app(InitiatePayment::class)->handle($codPayment, payContext());
    expect($codInit->redirectUrl)->toBeNull()->and($codPayment->refresh()->status)->toBe(PaymentStatus::Pending);
    app(CollectCodPayment::class)->handle($codPayment, $cod->grand_total);

    // Online: opened pending → initiated (redirect) → verified paid by re-query.
    $online = OrderFixtures::place([$this->variant->id => 1], PaymentMethod::Bkash);
    $onlinePayment = $online->payments()->sole();
    $onlineInit = app(InitiatePayment::class)->handle($onlinePayment, payContext());
    expect($onlineInit->redirectUrl)->toBe('https://pay.example.test/s/1');
    FakeOnlineGateway::$next = new GatewayResult(PaymentStatus::Paid, amount: $online->grand_total, transactionId: 'TRX-9', fee: Money::of('24.42'));
    app(VerifyPayment::class)->handle($onlinePayment);

    expect(payPath($codPayment->id))->toBe(['pending→paid'])
        ->and(payPath($onlinePayment->id))->toBe(['pending→initiated', 'initiated→paid']);

    // Every recorded step is a row of the one shared table.
    foreach ([...payPath($codPayment->id), ...payPath($onlinePayment->id)] as $step) {
        [$from, $to] = explode('→', $step);
        expect(PaymentStatus::from($from)->canTransitionTo(PaymentStatus::from($to)))->toBeTrue($step);
    }

    $onlinePayment->refresh();
    expect($codPayment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($codPayment->paid_at)->not->toBeNull()
        ->and($onlinePayment->status)->toBe(PaymentStatus::Paid)
        ->and($onlinePayment->transaction_id)->toBe('TRX-9')
        ->and((string) $onlinePayment->net_amount)->toBe((string) $online->grand_total->minus(Money::of('24.42')));
});

it('moves the order payment_status the same way for COD and online', function (): void {
    $cod = OrderFixtures::place([$this->variant->id => 1]);
    $online = OrderFixtures::place([$this->variant->id => 1], PaymentMethod::Bkash);
    expect($cod->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and($online->payment_status)->toBe(OrderPaymentStatus::Unpaid);

    app(InitiatePayment::class)->handle($cod->payments()->sole(), payContext());
    app(InitiatePayment::class)->handle($online->payments()->sole(), payContext());
    // COD is still owed (unpaid); the online customer is at the gateway (pending).
    expect($cod->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and($online->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending);

    app(CollectCodPayment::class)->handle($cod->payments()->sole(), $cod->grand_total);
    FakeOnlineGateway::$next = new GatewayResult(PaymentStatus::Paid, amount: $online->grand_total);
    app(VerifyPayment::class)->handle($online->payments()->sole());

    foreach ([$cod->refresh(), $online->refresh()] as $order) {
        expect($order->payment_status)->toBe(OrderPaymentStatus::Paid)
            ->and((string) $order->paid_total)->toBe((string) $order->grand_total)
            ->and((string) $order->due_total)->toBe('0.00');
    }
});

it('does not mark a payment paid when the reported amount differs (rule 19)', function (): void {
    $order = OrderFixtures::place([$this->variant->id => 1], PaymentMethod::Bkash);
    $payment = $order->payments()->sole();
    app(InitiatePayment::class)->handle($payment, payContext());

    FakeOnlineGateway::$next = new GatewayResult(PaymentStatus::Paid, amount: Money::of('10.00'));
    expect(fn () => app(VerifyPayment::class)->handle($payment))->toThrow(PaymentAmountMismatch::class);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending);
});

it('refuses a short COD collection', function (): void {
    $order = OrderFixtures::place([$this->variant->id => 1]);

    expect(fn () => app(CollectCodPayment::class)->handle($order->payments()->sole(), Money::of('100.00')))
        ->toThrow(PaymentAmountMismatch::class);
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);
});

it('treats a replayed result as a no-op', function (): void {
    Event::fake([PaymentStatusChanged::class]);
    $order = OrderFixtures::place([$this->variant->id => 1]);
    $payment = $order->payments()->sole();

    app(CollectCodPayment::class)->handle($payment, $order->grand_total);
    app(CollectCodPayment::class)->handle($payment, $order->grand_total);

    expect(payPath($payment->id))->toBe(['pending→paid']);
});

it('marks a failed online payment as failed on the order, and a retry reopens it', function (): void {
    $order = OrderFixtures::place([$this->variant->id => 1], PaymentMethod::Bkash);
    $payment = $order->payments()->sole();
    app(InitiatePayment::class)->handle($payment, payContext());

    FakeOnlineGateway::$next = new GatewayResult(PaymentStatus::Failed, failureReason: 'insufficient_balance');
    app(VerifyPayment::class)->handle($payment);
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Failed)
        ->and($payment->refresh()->failure_reason)->toBe('insufficient_balance');

    // A retry is a new payment row; the failed one never reopens.
    $retry = DB::transaction(fn () => app(PaymentTransitions::class)->open($order->id, PaymentMethod::Bkash, $order->grand_total));
    app(InitiatePayment::class)->handle($retry, payContext());
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Pending);
});

it('never deletes a payment', function (): void {
    $payment = Payment::factory()->create();

    expect(fn () => $payment->delete())->toThrow(PaymentViolation::class);
});

it('keeps the COD order unpaid through confirmation until collection', function (): void {
    $order = OrderFixtures::place([$this->variant->id => 1]);
    app(TransitionOrder::class)->handle($order, OrderStatus::Confirmed);

    expect(Order::query()->find($order->id)?->payment_status)->toBe(OrderPaymentStatus::Unpaid);
});
