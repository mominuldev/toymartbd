<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use Illuminate\Support\Facades\DB;

/** Reject a pending expense with a reason. Nothing reaches the ledger; it can be edited and resubmitted. */
final readonly class RejectExpense
{
    public function handle(Expense $expense, string $reason): Expense
    {
        return DB::transaction(static function () use ($expense, $reason): Expense {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== ExpenseStatus::Pending) {
                throw ExpenseConflict::cannot('rejected', $locked->status->value);
            }
            $locked->forceFill(['status' => ExpenseStatus::Rejected, 'rejected_reason' => $reason])->save();

            return $locked->refresh()->load('category');
        });
    }
}
