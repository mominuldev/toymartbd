<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Re-query the provider for the authoritative status and amount, then apply it (rule 19).
 * The webhook endpoint (P4-PAY-03) calls this after verifying the signature, never the
 * webhook body. Idempotent: a repeat that finds the same status writes nothing.
 */
final readonly class VerifyPayment
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentTransitions $transitions,
    ) {}

    public function handle(Payment $payment): Payment
    {
        $result = $this->gateways->for($payment->method)->verify($payment);

        return DB::transaction(fn (): Payment => $this->transitions->apply($payment, $result));
    }
}
