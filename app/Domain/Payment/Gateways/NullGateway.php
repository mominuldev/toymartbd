<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;

/**
 * A stand-in online gateway for CI and local work (CLAUDE.md rule 33a spirit): no online
 * gateway is chosen yet (Gate 1 decision 5), so the online refund path is exercised
 * against this driver and hand-written fixtures. NEVER registered in production — tests
 * register it for a method with `PaymentGatewayRegistry::register()`.
 *
 * Answers come from `$next*` (a test sets them) or the fixture defaults below. The shape
 * of `payload` is invented, not any provider's; replace with the real driver's fixtures
 * once the provider is chosen.
 */
final class NullGateway implements PaymentGateway
{
    public static ?RefundResult $nextRefund = null;

    /** @var list<array{payment_id: int, amount: string, reason: string}> refund calls, for assertions */
    public static array $refundCalls = [];

    public function __construct(private readonly PaymentMethod $method = PaymentMethod::Bkash) {}

    public static function reset(): void
    {
        self::$nextRefund = null;
        self::$refundCalls = [];
    }

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Initiated, gatewayReference: 'NULL-SESSION-'.$payment->id, payload: ['driver' => 'null']);
    }

    public function verify(Payment $payment): GatewayResult
    {
        return new GatewayResult($payment->status, amount: $payment->amount, payload: ['driver' => 'null']);
    }

    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        self::$refundCalls[] = ['payment_id' => $payment->id, 'amount' => $amount->toDecimal(), 'reason' => $reason];

        return self::$nextRefund ?? new RefundResult(RefundStatus::Completed, 'NULL-REFUND-'.$payment->id.'-'.count(self::$refundCalls), [
            'driver' => 'null',
            'fixture' => 'refund.completed',
            'amount' => $amount->toDecimal(),
        ]);
    }
}
