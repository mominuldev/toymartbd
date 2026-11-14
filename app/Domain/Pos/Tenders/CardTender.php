<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\Enums\PaymentMethod;

/** A card swiped on a stand-alone terminal; the cashier enters the approval code from the slip. No terminal is integrated. */
final readonly class CardTender extends ManualTender
{
    public function method(): PaymentMethod
    {
        return PaymentMethod::Card;
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
