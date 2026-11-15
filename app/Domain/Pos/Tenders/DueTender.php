<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Enums\CounterTender;
use App\Support\Money;
use LogicException;

/**
 * Due / baki (owner decision 2026-11-15): the customer takes the goods and owes the rest.
 * Nothing changes hands, so this driver records no payment — the order stays delivered with
 * `due_total` > 0 and its payment_status is re-derived (unpaid / partially paid). Needs a
 * known customer and `pos.sell_on_due`, and is held to the customer's due limit (PosDues).
 * It is collected later at the counter (CollectDue), as real payments.
 */
final readonly class DueTender implements TenderDriver
{
    public function method(): CounterTender
    {
        return CounterTender::Due;
    }

    public function requiresReference(): bool
    {
        return false;
    }

    public function givesChange(): bool
    {
        return false;
    }

    public function recordsPayment(): bool
    {
        return false;
    }

    public function requiresCustomer(): bool
    {
        return true;
    }

    public function capture(Payment $payment, Money $amount, ?string $reference, int $cashierId): GatewayResult
    {
        throw new LogicException('A due tender records no payment; nothing is captured.');
    }
}
