<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\SaveExpenseCategory;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\ExpenseCategoryRequest;
use App\Http\Resources\Admin\ExpenseCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Expense categories (DATABASE-DESIGN §3.7). A short list, returned whole (parents first,
 * then by name). `DELETE` deactivates — expenses and ledger entries keep pointing at it.
 */
final class ExpenseCategoryController extends ApiController
{
    /**
     * `GET /finance/expense-categories` — every category with its expense count; needs finance.view.
     * `meta.category_count` / `meta.active_count` / `meta.expense_count` are the server's counts for
     * the list caption ("8 categories · 141 expenses filed"), so the screen never adds them up.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $categories = ExpenseCategory::query()->withCount('expenses')->orderByRaw('COALESCE(parent_id, id)')->orderByRaw('parent_id IS NOT NULL')->orderBy('name')->get();

        return ExpenseCategoryResource::collection($categories)->additional(['meta' => [
            /** @var int */
            'category_count' => $categories->count(),
            /** @var int */
            'active_count' => $categories->where('is_active', true)->count(),
            /** @var int */
            'expense_count' => Expense::query()->count(),
        ]]);
    }

    /** `POST /finance/expense-categories` — needs finance.manage_expenses. */
    public function store(ExpenseCategoryRequest $request, SaveExpenseCategory $save): JsonResponse
    {
        return (new ExpenseCategoryResource($save->handle(null, $request->categoryAttributes())))->response()->setStatusCode(201);
    }

    /** `PATCH /finance/expense-categories/{category}` — needs finance.manage_expenses. */
    public function update(ExpenseCategoryRequest $request, ExpenseCategory $category, SaveExpenseCategory $save): ExpenseCategoryResource
    {
        return new ExpenseCategoryResource($save->handle($category, $request->categoryAttributes()));
    }

    /** `DELETE /finance/expense-categories/{category}` — deactivates (idempotent); needs finance.manage_expenses. */
    public function destroy(ExpenseCategory $category, SaveExpenseCategory $save): ExpenseCategoryResource
    {
        $this->authorize('delete', $category);

        return new ExpenseCategoryResource($save->handle($category, ['is_active' => false]));
    }
}
