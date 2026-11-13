<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reconciliation;

use App\Support\Money;

/**
 * One order's net profit, computed twice (see {@see OrderProfitBreakdown}):
 *
 *   - `$order`  from the source tables (orders, order_items, order_returns, refunds,
 *               payments, expenses) — what the order says happened;
 *   - `$ledger` from `financial_transactions` alone — what Finance booked.
 *
 * Every component is in its natural direction (a cost is positive), so
 * net = item_revenue − discount + delivery_charge − cogs + cogs_reversed − write_off
 *       − refunds − courier_charges − gateway_fees − order_expenses + other.
 */
final readonly class OrderBreakdownRow
{
    /**
     * @param  array<string, Money>  $order  component → amount (OrderProfitBreakdown::COMPONENTS)
     * @param  array<string, Money>  $ledger  component → amount
     * @param  list<string>  $mismatched  components whose two sides differ
     */
    public function __construct(
        public int $orderId,
        public string $number,
        public string $status,
        public ?string $paymentMethod,
        public ?string $placedOn,
        public ?string $deliveredOn,
        public bool $recognised,
        public array $order,
        public array $ledger,
        public Money $orderNet,
        public Money $ledgerNet,
        public ?Money $projectionNet,
        public array $mismatched,
    ) {}

    public function difference(): Money
    {
        return $this->orderNet->minus($this->ledgerNet);
    }

    public function agrees(): bool
    {
        return $this->mismatched === []
            && $this->difference()->isZero()
            && ($this->projectionNet ?? Money::zero())->equals($this->ledgerNet);
    }
}
