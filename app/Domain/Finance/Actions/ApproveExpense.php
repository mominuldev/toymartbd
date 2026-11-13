<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseEntries;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;

/**
 * Approve a pending expense (PRD N-04) and post it: one `out`, recognised entry dated the
 * expense date — cash as well if it was paid (rule 10). Who may approve is the Policy's
 * question (not the creator, unless super admin); this Action guards the state.
 */
final readonly class ApproveExpense
{
    public function __construct(private Ledger $ledger) {}

    public function handle(Expense $expense, ?int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $userId): Expense {
            $locked = Expense::query()->with('category')->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== ExpenseStatus::Pending) {
                throw ExpenseConflict::cannot('approved', $locked->status->value);
            }

            $locked->forceFill(['status' => ExpenseStatus::Approved, 'approved_by' => $userId, 'approved_at' => DhakaDate::instant()])->save();
            $this->ledger->record(ExpenseEntries::approval($locked));

            return $locked->refresh()->load('category');
        });
    }
}
