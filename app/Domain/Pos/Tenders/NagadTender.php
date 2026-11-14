<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\Enums\PaymentMethod;

/** Nagad send-money; the cashier enters the TxnID from the SMS. */
final readonly class NagadTender extends ManualTender
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::Nagad;
    }

    public function requiresReference(): bool
    {
        return true;
    }

    public function givesChange(): bool
    {
        return false;
    }
}
