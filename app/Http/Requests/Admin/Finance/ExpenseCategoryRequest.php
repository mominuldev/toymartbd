<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\Enums\ExpenseCategoryType;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An expense category, for `POST /finance/expense-categories` and `PATCH …/{category}`.
 * `parent_id` must be a top-level category (one level of nesting) and not itself.
 * `ledger_type` (optional) names the ledger type its expenses post as.
 */
final class ExpenseCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $category = $this->category();

        return $category === null
            ? ($this->user()?->can('create', ExpenseCategory::class) ?? false)
            : ($this->user()?->can('update', $category) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->category();
        $required = $category === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120', Rule::unique('expense_categories', 'name')
                ->where(fn ($q) => $q->where('parent_id', $this->input('parent_id', $category?->parent_id)))
                ->ignore($category?->id)],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'type' => [$required, Rule::enum(ExpenseCategoryType::class)],
            'ledger_type' => ['nullable', Rule::in(ExpenseCategoryType::LEDGER_TYPES)],
            'is_fixed' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'parent_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->whereNull('parent_id')],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $category = $this->category();
            $parent = $this->input('parent_id');
            if ($category !== null && $parent !== null && (int) $parent === $category->id) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent.');
            }
            if ($category !== null && $parent !== null && $category->children()->exists()) {
                $validator->errors()->add('parent_id', 'A category with subcategories must stay top-level.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function categoryAttributes(): array
    {
        return $this->safe()->only(['name', 'name_bn', 'type', 'ledger_type', 'is_fixed', 'is_active', 'parent_id']);
    }

    private function category(): ?ExpenseCategory
    {
        $category = $this->route('category');

        return $category instanceof ExpenseCategory ? $category : null;
    }
}
