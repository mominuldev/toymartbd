<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Pos\DTOs\DueCollectionPlan;
use App\Domain\Pos\DTOs\TenderInput;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Support\Money;

/**
 * Plans a due collection (owner decision 2026-11-15) — the only place its figures are computed
 * (rule 1). Read-only; CollectDue calls it under the customer's row lock with `lock: true`, so
 * the open orders are read FOR UPDATE in ascending id (the FIFO order is the lock order).
 *
 * Rules:
 *   - a known customer with open due (not the anonymous walk-in) — else `customer_phone`;
 *   - money tenders only (cash, bKash, Nagad, card); `due` is refused on `tenders.{i}.method`;
 *   - every amount positive; non-cash needs its reference; at most one cash tender;
 *   - non-cash together may not exceed the open due (nothing to give back as cash);
 *   - cash may be over-tendered: change = tendered − open due, from cash only.
 *
 * FIFO: the tenders, in the order given (cash at the amount kept, i.e. net of change), are
 * poured into the open orders oldest first; each (order, tender) pair is one payment row.
 */
final readonly class PosDueCollections
{
    public function __construct(
        private PosDues $dues,
        private TenderRegistry $tenders,
    ) {}

    /** @param  list<TenderInput>  $inputs */
    public function plan(?Customer $customer, array $inputs, bool $lock = false): DueCollectionPlan
    {
        $errors = [];
        $known = $customer !== null && ! PosCustomers::isWalkIn($customer);
        $orders = $known ? $this->dues->openOrders($customer->id, $lock) : collect();
        $openDue = $orders->reduce(static fn (Money $c, $o): Money => $c->plus($o->due_total), Money::zero());
        if ($openDue->isZero()) {
            $errors[] = ['field' => 'customer_phone', 'message' => (string) __('pos.due.nothing_open')];
        }

        $tendered = Money::zero();
        $cash = Money::zero();
        $nonCash = Money::zero();
        $cashCount = 0;
        foreach ($inputs as $i => $tender) {
            $driver = $this->tenders->for($tender->method);
            if (! $driver->recordsPayment()) {
                $errors[] = ['field' => "tenders.{$i}.method", 'message' => (string) __('pos.due.not_a_payment')];

                continue;
            }
            if (! $tender->amount->greaterThan(Money::zero())) {
                $errors[] = ['field' => "tenders.{$i}.amount", 'message' => (string) __('pos.tender.amount_positive')];

                continue;
            }
            $reference = $tender->reference === null ? '' : trim($tender->reference);
            if ($driver->requiresReference() && $reference === '') {
                $errors[] = ['field' => "tenders.{$i}.reference", 'message' => (string) __('pos.tender.reference_required')];
            }
            $tendered = $tendered->plus($tender->amount);
            if ($driver->givesChange()) {
                $cash = $cash->plus($tender->amount);
                $cashCount++;
            } else {
                $nonCash = $nonCash->plus($tender->amount);
            }
        }
        if ($cashCount > 1) {
            $errors[] = ['field' => 'tenders', 'message' => (string) __('pos.tender.one_cash')];
        }
        if (! $openDue->isZero() && $nonCash->greaterThan($openDue)) {
            $errors[] = ['field' => 'tenders', 'message' => (string) __('pos.due.non_cash_over', ['amount' => $openDue->toDecimal()])];
        }

        $applied = $tendered->greaterThan($openDue) ? $openDue : $tendered;
        $change = $tendered->minus($applied);
        if ($change->greaterThan($cash)) {
            $change = $cash; // only reachable with an error above
        }

        // FIFO: pour each tender (cash net of change) into the oldest orders first.
        $left = [];
        $rows = [];
        foreach ($orders as $order) {
            $left[$order->id] = $order->due_total;
            $rows[$order->id] = ['order' => $order, 'due' => $order->due_total, 'applied' => Money::zero()];
        }
        $slices = [];
        if ($errors === []) {
            foreach ($inputs as $tender) {
                $method = $tender->method->paymentMethod();
                if ($method === null) {
                    continue;
                }
                $fund = $this->tenders->for($tender->method)->givesChange() ? $tender->amount->minus($change) : $tender->amount;
                $reference = $tender->reference === null || trim($tender->reference) === '' ? null : trim($tender->reference);
                foreach ($left as $orderId => $owed) {
                    if (! $fund->greaterThan(Money::zero())) {
                        break;
                    }
                    if (! $owed->greaterThan(Money::zero())) {
                        continue;
                    }
                    $slice = $fund->greaterThan($owed) ? $owed : $fund;
                    $left[$orderId] = $owed->minus($slice);
                    $fund = $fund->minus($slice);
                    $rows[$orderId]['applied'] = $rows[$orderId]['applied']->plus($slice);
                    $slices[] = ['order_id' => $orderId, 'tender' => $tender->method, 'method' => $method, 'amount' => $slice, 'reference' => $reference];
                }
            }
        }

        return new DueCollectionPlan(
            customer: $known ? $customer : null,
            openDue: $openDue,
            orders: array_values($rows),
            tendered: $tendered,
            cashTendered: $cash,
            applied: $errors === [] ? $applied : Money::zero(),
            remaining: $errors === [] ? $openDue->minus($applied) : $openDue,
            change: $errors === [] ? $change : Money::zero(),
            slices: $slices,
            errors: $errors,
        );
    }
}
