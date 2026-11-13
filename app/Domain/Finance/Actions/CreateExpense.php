<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\DTOs\ExpenseData;
use App\Domain\Finance\DTOs\UploadedReceipt;
use App\Domain\Finance\Exceptions\ExpenseConflict;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Services\ExpenseAttachments;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enter an expense (PRD N-04). It starts `pending` and touches the ledger only when
 * approved. The receipt is stored before the row is written and removed again if the
 * transaction fails, so no row points at a missing file and no orphan file survives.
 */
final readonly class CreateExpense
{
    public function __construct(private ExpenseAttachments $attachments) {}

    public function handle(ExpenseData $data, ?UploadedReceipt $receipt, ?int $userId): Expense
    {
        $stored = $receipt === null ? null : $this->attachments->store($receipt->path);
        $clientName = $receipt === null ? 'receipt' : $receipt->clientName;

        try {
            return DB::transaction(function () use ($data, $clientName, $stored, $userId): Expense {
                $category = ExpenseCategory::query()->findOrFail($data->categoryId);
                if (! $category->is_active) {
                    throw ExpenseConflict::categoryInactive();
                }

                $expense = Expense::query()->create([
                    ...$data->attributes(),
                    ...($stored === null ? [] : [
                        'attachment_path' => $stored[0],
                        'attachment_mime' => $stored[1],
                        'attachment_size' => $stored[2],
                        'attachment_name' => ExpenseAttachments::displayName($clientName, $stored[1]),
                    ]),
                    'status' => 'pending',
                    'created_by' => $userId,
                ]);

                return $expense->refresh()->load('category');
            });
        } catch (Throwable $e) {
            $this->attachments->delete($stored[0] ?? null);

            throw $e;
        }
    }
}
