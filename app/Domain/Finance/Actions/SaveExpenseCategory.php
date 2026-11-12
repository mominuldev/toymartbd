<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;

/** Create or update an expense category. Validation (one level of nesting, unique name) is the FormRequest's. */
final readonly class SaveExpenseCategory
{
    /** @param array<string, mixed> $attributes */
    public function handle(?ExpenseCategory $category, array $attributes): ExpenseCategory
    {
        return DB::transaction(static function () use ($category, $attributes): ExpenseCategory {
            $category ??= new ExpenseCategory;
            $category->fill($attributes)->save();

            return $category->refresh();
        });
    }
}
