<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Finance\Actions\ApproveExpense;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\DeleteExpense;
use App\Domain\Finance\Actions\MarkExpensePaid;
use App\Domain\Finance\Actions\RejectExpense;
use App\Domain\Finance\Actions\UpdateExpense;
use App\Domain\Finance\Actions\VoidExpense;
use App\Domain\Finance\Enums\ExpenseStatus;
use App\Domain\Finance\Models\Expense;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\ExpenseDecisionRequest;
use App\Http\Requests\Admin\Finance\ExpenseReceiptRequest;
use App\Http\Requests\Admin\Finance\ExpenseRequest;
use App\Http\Requests\Admin\Finance\FinanceFilters;
use App\Http\Requests\Admin\Finance\PayExpenseRequest;
use App\Http\Resources\Admin\ExpenseResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Expenses (PRD N-04): entry with a receipt, approval by a second person, payment, void.
 * Only approval and void touch the ledger (an `out` recognised entry; its reversal); a move
 * the expense's status does not allow is a 409 `conflict`.
 */
final class ExpenseController extends ApiController
{
    public const int PER_PAGE = 25;

    public const array FILTERS = ['status', 'category_id', 'from', 'to', 'search', 'recurring', 'paid'];

    /** `GET /finance/expenses` — cursor-paginated, newest expense date first; needs finance.view. */
    #[QueryParameter('filter[status]', 'Comma-separated: pending, approved, rejected, void.', type: 'string')]
    #[QueryParameter('filter[category_id]', 'A category (its subcategories included).', type: 'int')]
    #[QueryParameter('filter[from]', 'First Dhaka expense date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka expense date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[search]', 'Contains-match on vendor, reference or description.', type: 'string')]
    #[QueryParameter('filter[recurring]', 'Only recurring templates (true) or only one-offs (false).', type: 'boolean')]
    #[QueryParameter('filter[paid]', 'Only paid (true) or only unpaid (false).', type: 'boolean')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'expense_date'|'-expense_date'|'amount'|'-amount'|'created_at'|'-created_at'", default: '-expense_date')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expense::class);
        $f = FinanceFilters::from($request, self::FILTERS, ['expense_date', 'amount', 'created_at'], '-expense_date');

        $query = Expense::query()->with('category');
        if (($statuses = $f->listOf('status', ExpenseStatus::values())) !== []) {
            $query->whereIn('status', $statuses);
        }
        if (($categoryId = $f->id('category_id')) !== null) {
            $query->whereIn('category_id', static fn ($q) => $q->select('id')->from('expense_categories')->where('id', $categoryId)->orWhere('parent_id', $categoryId));
        }
        if (($from = $f->date('from')) !== null) {
            $query->where('expense_date', '>=', $from);
        }
        if (($to = $f->date('to')) !== null) {
            $query->where('expense_date', '<=', $to);
        }
        if (($search = $f->search()) !== null) {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(static fn (Builder $q) => $q->where('vendor', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('description', 'like', $like));
        }
        if (($recurring = $f->bool('recurring')) !== null) {
            $query->where('is_recurring', $recurring);
        }
        if (($paid = $f->bool('paid')) !== null) {
            $paid ? $query->whereNotNull('payment_method') : $query->whereNull('payment_method');
        }

        $page = $query->orderBy((string) $f->sort, $f->descending ? 'desc' : 'asc')
            ->orderBy('id', $f->descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return ExpenseResource::collection($page);
    }

    /** `GET /finance/expenses/summary` — the stat cards for a Dhaka date range (default: the last 7 days). Needs finance.view. */
    #[QueryParameter('filter[from]', 'First Dhaka date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted filter or a bad date (`bad-request`)')]
    public function summary(Request $request, \App\Domain\Finance\Services\ExpenseSummary $summary): \App\Http\Resources\Admin\ExpenseSummaryResource
    {
        $this->authorize('viewAny', Expense::class);
        $f = FinanceFilters::from($request, ['from', 'to'], ['expense_date'], 'expense_date');
        $to = $f->date('to') ?? \App\Support\DhakaDate::today();
        $from = $f->date('from') ?? (\Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $to)?->subDays(6)->toDateString() ?? $to);

        return new \App\Http\Resources\Admin\ExpenseSummaryResource($summary->for($from, $to));
    }

    /** `POST /finance/expenses` — JSON or multipart with `receipt`; starts pending. Needs finance.manage_expenses. */
    #[OpenApiResponse(409, 'The category is inactive (`conflict`)')]
    public function store(ExpenseRequest $request, CreateExpense $create): JsonResponse
    {
        $expense = $create->handle($request->toData(), $request->receipt(), $request->user()?->id);

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    /** `GET /finance/expenses/{expense}` — needs finance.view. */
    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return new ExpenseResource($expense->load('category'));
    }

    /** `PATCH /finance/expenses/{expense}` — pending or rejected only (a rejected one returns to pending). */
    #[OpenApiResponse(409, 'The expense is approved or void (`conflict`)')]
    public function update(ExpenseRequest $request, Expense $expense, UpdateExpense $update): ExpenseResource
    {
        return new ExpenseResource($update->handle($expense, $request->toData(), null, false));
    }

    /** `DELETE /finance/expenses/{expense}` — pending or rejected only; an approved expense is voided instead. */
    #[OpenApiResponse(409, 'The expense is approved or void (`conflict`)')]
    public function destroy(Expense $expense, DeleteExpense $delete): Response
    {
        $this->authorize('delete', $expense);
        $delete->handle($expense);

        return response()->noContent();
    }

    /** `POST /finance/expenses/{expense}/approve` — posts it to the ledger. Not by the person who entered it. */
    #[OpenApiResponse(409, 'The expense is not pending (`conflict`)')]
    public function approve(ExpenseDecisionRequest $request, Expense $expense, ApproveExpense $approve): ExpenseResource
    {
        return new ExpenseResource($approve->handle($expense, $request->user()?->id));
    }

    /** `POST /finance/expenses/{expense}/reject` — with a `reason`; nothing reaches the ledger. */
    #[OpenApiResponse(409, 'The expense is not pending (`conflict`)')]
    public function reject(ExpenseDecisionRequest $request, Expense $expense, RejectExpense $reject): ExpenseResource
    {
        return new ExpenseResource($reject->handle($expense, $request->reason()));
    }

    /** `POST /finance/expenses/{expense}/void` — with a `reason`; posts the reversing entry dated today (Dhaka). */
    #[OpenApiResponse(409, 'The expense is not approved (`conflict`)')]
    public function void(ExpenseDecisionRequest $request, Expense $expense, VoidExpense $void): ExpenseResource
    {
        return new ExpenseResource($void->handle($expense, $request->reason(), $request->user()?->id));
    }

    /** `POST /finance/expenses/{expense}/pay` — record payment of an unpaid expense (cash entry if approved). */
    #[OpenApiResponse(409, 'Already paid, or rejected/void (`conflict`)')]
    public function pay(PayExpenseRequest $request, Expense $expense, MarkExpensePaid $pay): ExpenseResource
    {
        return new ExpenseResource($pay->handle($expense, $request->paymentMethod(), $request->paidOn()));
    }

    /** `POST /finance/expenses/{expense}/receipt` — attach or replace the receipt (pending/rejected only). */
    #[OpenApiResponse(409, 'The expense is approved or void (`conflict`)')]
    #[OpenApiResponse(422, 'Not a PDF/JPEG/PNG/WebP by content, or over 10 MB (`validation-error` on `receipt`)')]
    public function storeReceipt(ExpenseReceiptRequest $request, Expense $expense, UpdateExpense $update): ExpenseResource
    {
        return new ExpenseResource($update->handle($expense, \App\Domain\Finance\DTOs\ExpenseData::fromExpense($expense), $request->receipt(), false));
    }

    /** `DELETE /finance/expenses/{expense}/receipt` — remove the receipt (pending/rejected only). */
    #[OpenApiResponse(409, 'The expense is approved or void (`conflict`)')]
    public function destroyReceipt(Expense $expense, UpdateExpense $update): ExpenseResource
    {
        $this->authorize('update', $expense);

        return new ExpenseResource($update->handle($expense, \App\Domain\Finance\DTOs\ExpenseData::fromExpense($expense), null, true));
    }

    /** `GET /finance/expenses/{expense}/receipt` — the file, as a download only; needs finance.view. */
    #[OpenApiResponse(404, 'The expense has no receipt (`not-found`)')]
    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);
        $disk = Storage::disk(MediaUrls::originalsDisk());
        if ($expense->attachment_path === null || ! $disk->exists($expense->attachment_path)) {
            throw new NotFoundHttpException('This expense has no receipt.');
        }

        return $disk->download($expense->attachment_path, $expense->attachment_name ?? 'receipt', [
            'Content-Type' => $expense->attachment_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
