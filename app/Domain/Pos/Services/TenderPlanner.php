<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Domain\Pos\DTOs\TenderInput;
use App\Domain\Pos\DTOs\TenderPlan;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Support\Money;

/**
 * Split tenders against a total — the only place change is computed (rule 1):
 *
 *   - every amount is positive; a non-cash tender needs its reference (driver rule);
 *   - at most one cash tender (the drawer has one cash line), at most one due tender;
 *   - non-cash tenders — due included — together may not exceed the total: a card or bKash
 *     payment cannot be "over-paid" and given back as cash, and nobody owes more than the bill;
 *   - change = tendered + due − total, never more than the cash handed over;
 *   - remaining = total − tendered − due while short (a charge refuses until it is zero).
 *
 * Payment rows: each non-cash money tender at its amount, cash at what stays in the drawer
 * (cash − change), nothing for due (its driver records no payment). Σ rows + due = total
 * exactly when the plan settles.
 *
 * Who may put a sale on due, for whom and how much (permission, known customer, limit) is
 * not tender arithmetic: PosDues adds those errors.
 */
final readonly class TenderPlanner
{
    public function __construct(private TenderRegistry $tenders) {}

    /** @param  list<TenderInput>  $inputs */
    public function plan(Money $total, array $inputs): TenderPlan
    {
        $errors = [];
        $tendered = Money::zero();
        $cash = Money::zero();
        $nonCash = Money::zero();
        $due = Money::zero();
        $dueIndex = null;
        $cashCount = 0;
        $dueCount = 0;

        foreach ($inputs as $i => $tender) {
            $driver = $this->tenders->for($tender->method);
            if (! $driver->recordsPayment()) {
                $dueIndex ??= $i;
                $dueCount++;
            }
            if (! $tender->amount->greaterThan(Money::zero())) {
                $errors[] = ['field' => "tenders.{$i}.amount", 'message' => (string) __('pos.tender.amount_positive')];

                continue;
            }
            $reference = $tender->reference === null ? '' : trim($tender->reference);
            if ($driver->requiresReference() && $reference === '') {
                $errors[] = ['field' => "tenders.{$i}.reference", 'message' => (string) __('pos.tender.reference_required')];
            }
            if ($driver->recordsPayment()) {
                $tendered = $tendered->plus($tender->amount);
            } else {
                $due = $due->plus($tender->amount);
            }
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
        if ($dueCount > 1) {
            $errors[] = ['field' => 'tenders', 'message' => (string) __('pos.tender.one_due')];
        }
        if ($nonCash->greaterThan($total)) {
            $errors[] = ['field' => 'tenders', 'message' => (string) __($due->isZero() ? 'pos.tender.non_cash_over' : 'pos.tender.due_over')];
        }

        $settled = $tendered->plus($due);
        $remaining = $total->greaterThan($settled) ? $total->minus($settled) : Money::zero();
        $change = $settled->greaterThan($total) ? $settled->minus($total) : Money::zero();
        if ($change->greaterThan($cash)) {
            $change = $cash; // only reachable with an error above (non-cash over the total)
        }

        $payments = [];
        if ($errors === [] && $remaining->isZero()) {
            foreach ($inputs as $tender) {
                $driver = $this->tenders->for($tender->method);
                $method = $tender->method->paymentMethod();
                if (! $driver->recordsPayment() || $method === null) {
                    continue; // due: the unpaid part of the order, no payment row
                }
                $amount = $driver->givesChange() ? $tender->amount->minus($change) : $tender->amount;
                if ($amount->isZero()) {
                    continue; // all the cash went back as change (exact non-cash + a cash note)
                }
                $reference = $tender->reference === null || trim($tender->reference) === '' ? null : trim($tender->reference);
                $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => $reference];
            }
        }

        return new TenderPlan($tendered, $cash, $remaining, $change, $payments, $errors, $due, $dueIndex);
    }
}
