<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\Enums\ExpensePaymentMethod;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseEntries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record payment of an expense entered unpaid. Pending: just records the method (approval
 * will post it as cash). Approved: posts the cash-only `expense_payment` entry — the cost is
 * already recognised, so it is not recognised twice (rule 10).
 */
final readonly class MarkExpensePaid
{
    public function __construct(private Ledger $ledger) {}

    public function handle(Expense $expense, ExpensePaymentMethod $method, CarbonImmutable $paidOn): Expense
    {
        return DB::transaction(function () use ($expense, $method, $paidOn): Expense {
            $locked = Expense::query()->with('category')->lockForUpdate()->findOrFail($expense->id);
            if ($locked->isPaid()) {
                throw ExpenseConflict::alreadyPaid();
            }
            if (! in_array($locked->status, [ExpenseStatus::Pending, ExpenseStatus::Approved], true)) {
                throw ExpenseConflict::cannot('marked paid', $locked->status->value);
            }

            $locked->forceFill(['payment_method' => $method, 'paid_on' => $paidOn->toDateString()])->save();
            if ($locked->status === ExpenseStatus::Approved) {
                $this->ledger->record(ExpenseEntries::payment($locked));
            }

            return $locked->refresh()->load('category');
        });
    }
}
