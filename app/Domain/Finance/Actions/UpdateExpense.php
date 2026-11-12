<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\DTOs\ExpenseData;
use App\Domain\Finance\DTOs\UploadedReceipt;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\ExpenseAttachments;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Edit a pending or rejected expense (a rejected one returns to pending for re-approval).
 * Approved and void expenses are immutable: they are on the ledger. A new receipt replaces
 * the old file after commit; `removeReceipt` drops it.
 */
final readonly class UpdateExpense
{
    public function __construct(private ExpenseAttachments $attachments) {}

    public function handle(Expense $expense, ExpenseData $data, ?UploadedReceipt $receipt, bool $removeReceipt): Expense
    {
        $stored = $receipt === null ? null : $this->attachments->store($receipt->path);
        $clientName = $receipt === null ? 'receipt' : $receipt->clientName;
        $old = $expense->attachment_path;

        try {
            $updated = DB::transaction(function () use ($expense, $data, $clientName, $stored, $removeReceipt): Expense {
                $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
                if (! $locked->status->isEditable()) {
                    throw ExpenseConflict::notEditable($locked->status->value);
                }
                $category = ExpenseCategory::query()->findOrFail($data->categoryId);
                if (! $category->is_active && $category->id !== $locked->category_id) {
                    throw ExpenseConflict::categoryInactive();
                }

                $locked->fill($data->attributes());
                $locked->status = ExpenseStatus::Pending;
                $locked->rejected_reason = null;
                if ($stored !== null) {
                    $locked->fill([
                        'attachment_path' => $stored[0],
                        'attachment_mime' => $stored[1],
                        'attachment_size' => $stored[2],
                        'attachment_name' => ExpenseAttachments::displayName($clientName, $stored[1]),
                    ]);
                } elseif ($removeReceipt) {
                    $locked->fill(['attachment_path' => null, 'attachment_mime' => null, 'attachment_size' => null, 'attachment_name' => null]);
                }
                $locked->save();

                return $locked->refresh()->load('category');
            });
        } catch (Throwable $e) {
            $this->attachments->delete($stored[0] ?? null);

            throw $e;
        }

        if ($old !== null && $old !== $updated->attachment_path) {
            $this->attachments->delete($old);
        }

        return $updated;
    }
}
