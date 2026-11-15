<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Support\Money;

/**
 * The server's reading of the tenders against a total (rule 1 — the register never adds them
 * up itself): what was handed over, what is put on due, what is still owed, the change to
 * give back, and the payment rows a charge would write (cash at the amount KEPT, i.e. net of
 * change; a due tender writes none).
 *
 * `tendered` is money handed over (due excluded); `due` is the part of the total the customer
 * takes on credit (owner decision 2026-11-15). Invariant when `errors` is empty and
 * `remaining` is zero:
 *   Σ payments.amount + due = total   and   tendered + due − change = total.
 */
final readonly class TenderPlan
{
    /**
     * @param  list<array{method: \App\Domain\Payment\Enums\PaymentMethod, amount: Money, reference: string|null}>  $payments
     * @param  list<array{field: string, message: string}>  $errors
     * @param  int|null  $dueIndex  the position of the due tender in the input (for field errors)
     */
    public function __construct(
        public Money $tendered,
        public Money $cashTendered,
        public Money $remaining,
        public Money $change,
        public array $payments,
        public array $errors,
        public ?Money $due = null,
        public ?int $dueIndex = null,
    ) {}

    public function settles(): bool
    {
        return $this->errors === [] && $this->remaining->isZero();
    }

    public function dueAmount(): Money
    {
        return $this->due ?? Money::zero();
    }

    public function hasDue(): bool
    {
        return $this->due !== null && $this->due->greaterThan(Money::zero());
    }

    /**
     * The same plan with more errors (the due guards). A plan with errors writes no payments.
     *
     * @param  list<array{field: string, message: string}>  $errors
     */
    public function withErrors(array $errors): self
    {
        if ($errors === []) {
            return $this;
        }

        return new self($this->tendered, $this->cashTendered, $this->remaining, $this->change, [], [...$this->errors, ...$errors], $this->due, $this->dueIndex);
    }
}
