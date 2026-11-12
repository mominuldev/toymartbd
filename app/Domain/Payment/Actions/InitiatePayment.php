<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Open a payment with its provider, after the order's transaction has committed (rule 4):
 * the driver call happens first, outside any transaction, then its answer is applied in a
 * short one. COD comes back `pending` (no-op); an online driver returns `initiated` and a
 * redirect URL for the storefront.
 */
final readonly class InitiatePayment
{
    public function __construct(
        private PaymentGatewayRegistry $gateways,
        private PaymentTransitions $transitions,
    ) {}

    public function handle(Payment $payment, PaymentContext $context): GatewayResult
    {
        $result = $this->gateways->for($payment->method)->initiate($payment, $context);
        DB::transaction(fn (): Payment => $this->transitions->apply($payment, $result));

        return $result;
    }
}
