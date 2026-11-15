<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;

/**
 * The shared shape of a manual counter tender: the staff-entered amount and reference ARE
 * the record (there is no provider to ask), so the result is `paid` with no fee. The
 * payload says so — a manual tender is never mistaken for a verified gateway capture.
 */
abstract readonly class ManualTender implements TenderDriver
{
    public function recordsPayment(): bool
    {
        return true;
    }

    public function requiresCustomer(): bool
    {
        return false;
    }

    public function capture(Payment $payment, Money $amount, ?string $reference, int $cashierId): GatewayResult
    {
        return new GatewayResult(
            status: PaymentStatus::Paid,
            amount: $amount,
            gatewayReference: $reference,
            fee: Money::zero(),
            payload: [
                'driver' => 'pos_manual',
                'tender' => $this->method()->value,
                'manual' => true,
                'reference' => $reference,
                'cashier_id' => $cashierId,
            ],
        );
    }
}
