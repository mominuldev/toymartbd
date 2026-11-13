<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Finance\Services\Receivables;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\FinanceFilters;
use App\Http\Resources\Admin\FinanceLedgerEntryResource;
use App\Http\Resources\Admin\FinanceReceivablesResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The ledger browser and the receivables card (P5 finance). Read-only: the ledger is
 * append-only and no endpoint writes it directly (rule 8). Needs finance.view.
 */
final class FinanceLedgerController extends ApiController
{
    public const int PER_PAGE = 50;

    public const array FILTERS = ['type', 'direction', 'source_type', 'source_id', 'category_id', 'cash', 'recognised', 'from', 'to'];

    /**
     * `GET /finance/ledger` — ledger entries, cursor-paginated, newest Dhaka date first.
     */
    #[QueryParameter('filter[type]', 'Comma-separated ledger types, e.g. `sale_revenue,cogs`.', type: 'string')]
    #[QueryParameter('filter[direction]', '`in` or `out`.', type: "'in'|'out'")]
    #[QueryParameter('filter[source_type]', 'e.g. `order`, `expense`, `manual_cod_receipt`.', type: 'string')]
    #[QueryParameter('filter[source_id]', 'With source_type: one source row.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'Expense category.', type: 'int')]
    #[QueryParameter('filter[cash]', 'Only cash movements (true) or only non-cash (false).', type: 'boolean')]
    #[QueryParameter('filter[recognised]', 'Only P&L entries (true) or only non-P&L (false).', type: 'boolean')]
    #[QueryParameter('filter[from]', 'First Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'occurred_on'|'-occurred_on'|'amount'|'-amount'", default: '-occurred_on')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FinancialTransaction::class);
        $f = FinanceFilters::from($request, self::FILTERS, ['occurred_on', 'amount'], '-occurred_on');

        $query = FinancialTransaction::query()->with('category');
        if (($types = $f->listOf('type', LedgerEntryType::values())) !== []) {
            $query->whereIn('type', $types);
        }
        if (($direction = $f->listOf('direction', Direction::values())) !== []) {
            $query->whereIn('direction', $direction);
        }
        if (($source = $f->key('source_type')) !== null) {
            $query->where('source_type', $source);
        }
        if (($sourceId = $f->id('source_id')) !== null) {
            $query->where('source_id', $sourceId);
        }
        if (($categoryId = $f->id('category_id')) !== null) {
            $query->where('category_id', $categoryId);
        }
        if (($cash = $f->bool('cash')) !== null) {
            $query->where('is_cash_movement', $cash);
        }
        if (($recognised = $f->bool('recognised')) !== null) {
            $query->where('is_recognised_revenue', $recognised);
        }
        if (($from = $f->date('from')) !== null) {
            $query->where('occurred_on', '>=', $from);
        }
        if (($to = $f->date('to')) !== null) {
            $query->where('occurred_on', '<=', $to);
        }

        $page = $query->orderBy((string) $f->sort, $f->descending ? 'desc' : 'asc')
            ->orderBy('id', $f->descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return FinanceLedgerEntryResource::collection($page);
    }

    /** `GET /finance/receivables` — COD receivables (recognised − received) as at `as_of` (default today, Dhaka). */
    #[QueryParameter('as_of', 'A Dhaka date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'as_of is not a date (`bad-request`)')]
    public function receivables(Request $request, Receivables $receivables): FinanceReceivablesResource
    {
        $this->authorize('viewReceivables', FinancialTransaction::class);
        $asOf = $request->query('as_of');
        if ($asOf !== null && (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('as_of must be a date, YYYY-MM-DD.');
        }
        $asOf = is_string($asOf) ? $asOf : DhakaDate::today();

        return new FinanceReceivablesResource(['as_of' => $asOf, ...$receivables->summary($asOf)]);
    }
}
