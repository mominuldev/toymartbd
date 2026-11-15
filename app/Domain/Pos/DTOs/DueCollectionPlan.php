<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Pos\Enums\CounterTender;
use App\Support\Money;

/**
 * The server's reading of money a customer brings against their open due (rule 1): which of
 * their open POS orders it pays, oldest first (FIFO), the change from cash, and what stays owed.
 *
 *   applied = min(tendered, open due)      change = tendered − applied (only ever from cash)
 *   remaining = open due − applied         (a partial collection is allowed)
 *
 * `slices` are the payment rows a collection writes: one per (order, tender) pair the FIFO
 * walk produced, cash at the amount kept. Σ slices = applied when there are no errors.
 */
final readonly class DueCollectionPlan
{
    /**
     * @param  list<array{order: Order, due: Money, applied: Money}>  $orders
     * @param  list<array{order_id: int, tender: CounterTender, method: PaymentMethod, amount: Money, reference: string|null}>  $slices
     * @param  list<array{field: string, message: string}>  $errors
     */
    public function __construct(
        public ?Customer $customer,
        public Money $openDue,
        public array $orders,
        public Money $tendered,
        public Money $cashTendered,
        public Money $applied,
        public Money $remaining,
        public Money $change,
        public array $slices,
        public array $errors,
    ) {}

    public function chargeable(): bool
    {
        return $this->errors === [] && $this->applied->greaterThan(Money::zero());
    }
}
