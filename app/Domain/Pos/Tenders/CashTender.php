<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\Enums\PaymentMethod;

/** Cash in hand. The amount recorded is what stays in the drawer — cash tendered minus change. */
final readonly class CashTender extends ManualTender
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::Cash;
    }

    public function requiresReference(): bool
    {
        return false;
    }

    public function givesChange(): bool
    {
        return true;
    }
}
