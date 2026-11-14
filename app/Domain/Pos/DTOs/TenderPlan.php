<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Support\Money;

/**
 * The server's reading of the tenders against a total (rule 1 — the register never adds them
 * up itself): what was handed over, what is still due, the change to give back, and the
 * payment rows a charge would write (cash at the amount KEPT, i.e. net of change).
 *
 * Invariant when `errors` is empty and `remaining` is zero:
 *   Σ payments.amount = total   and   tendered − change = total.
 */
final readonly class TenderPlan
{
    /**
     * @param  list<array{method: \App\Domain\Payment\Enums\PaymentMethod, amount: Money, reference: string|null}>  $payments
     * @param  list<array{field: string, message: string}>  $errors
     */
    public function __construct(
        public Money $tendered,
        public Money $cashTendered,
        public Money $remaining,
        public Money $change,
        public array $payments,
        public array $errors,
    ) {}

    public function settles(): bool
    {
        return $this->errors === [] && $this->remaining->isZero();
    }
}
