<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Pos\Enums\CounterTender;

/** A card swiped on a stand-alone terminal; the cashier enters the approval code from the slip. No terminal is integrated. */
final readonly class CardTender extends ManualTender
{
    public function method(): CounterTender
    {
        return CounterTender::Card;
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
