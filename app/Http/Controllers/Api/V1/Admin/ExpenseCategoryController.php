<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\SaveExpenseCategory;
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
    /** `GET /finance/expense-categories` — every category with its expense count; needs finance.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        return ExpenseCategoryResource::collection(
            ExpenseCategory::query()->withCount('expenses')->orderByRaw('COALESCE(parent_id, id)')->orderByRaw('parent_id IS NOT NULL')->orderBy('name')->get(),
        );
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
