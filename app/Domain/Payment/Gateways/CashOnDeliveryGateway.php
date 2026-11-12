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
 * Cash on delivery as a first-class driver (rule 33, P4-PAY-01). There is no remote
 * provider: the courier is the collector, so
 *
 *  - `initiate` opens nothing and leaves the payment `pending` (no redirect);
 *  - `verify` answers with what we already know — the authority for a COD payment is the
 *    courier's delivery, recorded through CollectCodPayment (the same PaymentTransitions
 *    path an online gateway's verified result takes: pending → paid);
 *  - `refund` is a manual payout by staff (cash, bKash / Nagad send-money, bank):
 *    `processing` until staff record it through {@see manualPayout()} with the method and
 *    the transaction reference they used (the refund flow's PayRefund).
 *
 * COD remittance by the courier (cash actually reaching us) is Finance's `cod_received`
 * (§4.5), not a payment status — revenue and cash stay separate (rule 10).
 */
final readonly class CashOnDeliveryGateway implements PaymentGateway
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::Cod;
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        return new GatewayResult(status: $payment->status, payload: ['driver' => 'cod', 'action' => 'initiate']);
    }

    public function verify(Payment $payment): GatewayResult
    {
        return new GatewayResult(
            status: $payment->status,
            amount: $payment->status === PaymentStatus::Paid ? $payment->amount : null,
            payload: ['driver' => 'cod', 'action' => 'verify'],
        );
    }

    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        return new RefundResult(RefundStatus::Processing, payload: ['driver' => 'cod', 'manual_payout' => true]);
    }

    /**
     * Staff sent the money back by hand and entered how. There is no provider to ask, so
     * the staff-entered method and reference ARE the record; the result is `completed`.
     */
    public function manualPayout(Payment $payment, Money $amount, PaymentMethod $via, ?string $reference): RefundResult
    {
        return new RefundResult(RefundStatus::Completed, $reference, [
            'driver' => 'cod',
            'manual_payout' => true,
            'via' => $via->value,
            'amount' => $amount->toDecimal(),
        ]);
    }

    /** The courier collected the cash on delivery. */
    public function collected(Payment $payment, Money $amount, ?string $reference = null): GatewayResult
    {
        return new GatewayResult(
            status: PaymentStatus::Paid,
            amount: $amount,
            gatewayReference: $reference,
            fee: Money::zero(),
            payload: ['driver' => 'cod', 'action' => 'collected'],
        );
    }
}
