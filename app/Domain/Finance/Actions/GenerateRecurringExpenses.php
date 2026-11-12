<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generate this period's copies of every recurring expense template (rent, salaries…),
 * scheduled on the 1st of each month at 00:30 Dhaka. A template is an approved, non-void
 * expense with `is_recurring`; a copy is dated the 1st of the period, starts `pending`
 * (someone still approves it — the amount may have changed), and carries no receipt.
 *
 * Idempotent: UNIQUE (recurrence_parent_id, recurrence_period) and an existence check
 * mean a re-run, or a run for a past month, never generates twice. A template's own month
 * is never generated (the template is that month's expense).
 */
final readonly class GenerateRecurringExpenses
{
    /** @param string $period Y-m (a Dhaka month) */
    public function handle(string $period, ?int $userId = null): int
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $period.'-01');
        if ($first === null || $first->format('Y-m') !== $period) {
            throw new InvalidArgumentException("Not a Y-m period: [{$period}]");
        }

        $templates = Expense::query()
            ->where('is_recurring', true)
            ->whereNull('recurrence_parent_id')
            ->where('status', ExpenseStatus::Approved->value)
            ->where('expense_date', '<', $first->toDateString())
            ->get();

        $made = 0;
        foreach ($templates as $template) {
            $start = CarbonImmutable::instance($template->expense_date);
            if ($template->recurrence === null || ! $template->recurrence->isDueIn((int) $start->format('n'), (int) $first->format('n'))) {
                continue;
            }

            $made += DB::transaction(static function () use ($template, $period, $first, $userId): int {
                $exists = Expense::query()->where('recurrence_parent_id', $template->id)
                    ->where('recurrence_period', $period)->lockForUpdate()->exists();
                if ($exists) {
                    return 0;
                }
                Expense::query()->create([
                    'category_id' => $template->category_id,
                    'amount' => $template->amount,
                    'expense_date' => $first->toDateString(),
                    'status' => ExpenseStatus::Pending,
                    'reference' => $template->reference,
                    'vendor' => $template->vendor,
                    'description' => $template->description,
                    'is_recurring' => false,
                    'recurrence_parent_id' => $template->id,
                    'recurrence_period' => $period,
                    'created_by' => $userId ?? $template->created_by,
                ]);

                return 1;
            });
        }

        return $made;
    }
}
