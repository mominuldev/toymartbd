<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\ExpenseAttachments;
use Illuminate\Support\Facades\DB;

/**
 * Delete a pending or rejected expense — it never reached the ledger. An approved one is
 * voided instead (a reversing entry), never deleted (rule 8). Its receipt goes after commit.
 */
final readonly class DeleteExpense
{
    public function __construct(private ExpenseAttachments $attachments) {}

    public function handle(Expense $expense): void
    {
        $path = DB::transaction(static function () use ($expense): ?string {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if (! $locked->status->isEditable()) {
                throw ExpenseConflict::cannot('deleted', $locked->status->value);
            }
            $path = $locked->attachment_path;
            $locked->delete();

            return $path;
        });

        $this->attachments->delete($path);
    }
}
