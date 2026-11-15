<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Pos\Enums\CounterTender;

/** bKash send-money to the shop's merchant number; the cashier enters the TrxID from the SMS. */
final readonly class BkashTender extends ManualTender
{
    public function method(): CounterTender
    {
        return CounterTender::Bkash;
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
