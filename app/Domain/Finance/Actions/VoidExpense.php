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
 * Void an approved expense: posts the exact reversing entry, dated today in Dhaka (the
 * correction happens now; the original stays on its own day — rule 8, never rewritten).
 */
final readonly class VoidExpense
{
    public function __construct(private Ledger $ledger) {}

    public function handle(Expense $expense, string $reason, ?int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $userId): Expense {
            $locked = Expense::query()->with('category')->lockForUpdate()->findOrFail($expense->id);
            if ($locked->status !== ExpenseStatus::Approved) {
                throw ExpenseConflict::cannot('voided', $locked->status->value);
            }

            $locked->forceFill(['status' => ExpenseStatus::Void, 'voided_by' => $userId, 'voided_at' => DhakaDate::instant(), 'void_reason' => $reason])->save();
            $this->ledger->record(ExpenseEntries::void($locked, DhakaDate::todayDate()));

            return $locked->refresh()->load('category');
        });
    }
}
