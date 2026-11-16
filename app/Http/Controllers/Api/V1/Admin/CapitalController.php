<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\RecordCapitalEntry;
use App\Domain\Finance\Actions\ReverseCapitalEntry;
use App\Domain\Finance\Enums\CapitalEntryKind;
use App\Domain\Finance\Models\CapitalEntry;
use App\Domain\Finance\Services\CapitalSummary;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\Capital\RecordCapitalEntryRequest;
use App\Http\Requests\Admin\Finance\Capital\ReverseCapitalEntryRequest;
use App\Http\Requests\Admin\Finance\FinanceFilters;
use App\Http\Resources\Admin\CapitalEntryResource;
use App\Http\Resources\Admin\CapitalSummaryResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Owner capital (P10): money the owner or a partner put in ("New investment") and drawings
 * taken out. Each entry writes one cash-only ledger entry (`owner_capital_in` /
 * `owner_drawing`) — never revenue, never on the P&L (rule 10). Entries are insert-only; a
 * mistake is reversed, never edited. Read with finance.view; write with finance.manage_capital.
 */
final class CapitalController extends ApiController
{
    public const int PER_PAGE = 50;

    public const array FILTERS = ['holder_id', 'kind', 'from', 'to'];

    /** `GET /finance/capital` — per holder: put in, taken out, net, latest entry; and the totals. */
    #[QueryParameter('filter[holder_id]', 'One capital holder.', type: 'int')]
    #[QueryParameter('filter[kind]', '`contribution` or `drawing`.', type: "'contribution'|'drawing'")]
    #[QueryParameter('filter[from]', 'First Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid filter value (`bad-request`)')]
    public function summary(Request $request, CapitalSummary $summary): CapitalSummaryResource
    {
        $this->authorize('viewAny', CapitalEntry::class);
        $f = FinanceFilters::from($request, self::FILTERS, ['occurred_on'], '-occurred_on');
        if ($request->has('sort')) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('The capital overview takes no sort.');
        }
        [$holderId, $kind, $from, $to] = $this->filters($f);

        return new CapitalSummaryResource(['from' => $from, 'to' => $to, ...$summary->summary($holderId, $kind, $from, $to)]);
    }

    /** `GET /finance/capital/entries` — entries, cursor-paginated, newest Dhaka date first; `meta.total` counts every match. */
    #[QueryParameter('filter[holder_id]', 'One capital holder.', type: 'int')]
    #[QueryParameter('filter[kind]', '`contribution` or `drawing`.', type: "'contribution'|'drawing'")]
    #[QueryParameter('filter[from]', 'First Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'occurred_on'|'-occurred_on'|'amount'|'-amount'", default: '-occurred_on')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function entries(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CapitalEntry::class);
        $f = FinanceFilters::from($request, self::FILTERS, ['occurred_on', 'amount'], '-occurred_on');
        [$holderId, $kind, $from, $to] = $this->filters($f);

        $query = CapitalEntry::query()
            ->when($holderId !== null, static fn ($q) => $q->where('holder_id', $holderId))
            ->when($kind !== null, static fn ($q) => $q->where('kind', $kind?->value))
            ->when($from !== null, static fn ($q) => $q->where('occurred_on', '>=', $from))
            ->when($to !== null, static fn ($q) => $q->where('occurred_on', '<=', $to));
        $total = (clone $query)->count();

        $page = $query->with(['holder', 'recorder', 'reversal'])
            ->orderBy((string) $f->sort, $f->descending ? 'desc' : 'asc')
            ->orderBy('id', $f->descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        // `meta.total`: how many entries match the filters ("Showing 6 of 15"), beside the cursor.
        return CapitalEntryResource::collection($page)->additional(['meta' => ['total' => $total]]);
    }

    /** `POST /finance/capital/entries` — record money put in or taken out. Needs an `Idempotency-Key`. */
    #[OpenApiResponse(409, 'The holder was deactivated meanwhile (`conflict`)')]
    public function store(RecordCapitalEntryRequest $request, RecordCapitalEntry $record): JsonResponse
    {
        $entry = $record->handle(
            $request->holder(),
            $request->kind(),
            $request->amount(),
            $request->occurredOn(),
            $request->paymentMethod(),
            $request->reference(),
            $request->note(),
            $request->user()?->id,
        );

        return (new CapitalEntryResource($entry))->response()->setStatusCode(201);
    }

    /** `POST /finance/capital/entries/{entry}/reverse` — the mirror entry, dated today (Dhaka). Returns the reversal. Needs an `Idempotency-Key`. */
    #[OpenApiResponse(409, 'Already reversed, or the entry is itself a reversal (`conflict`)')]
    public function reverse(ReverseCapitalEntryRequest $request, CapitalEntry $entry, ReverseCapitalEntry $reverse): JsonResponse
    {
        return (new CapitalEntryResource($reverse->handle($entry, $request->reason(), $request->user()?->id)))->response()->setStatusCode(201);
    }

    /** @return array{0: int|null, 1: CapitalEntryKind|null, 2: string|null, 3: string|null} */
    private function filters(FinanceFilters $f): array
    {
        $kinds = $f->listOf('kind', CapitalEntryKind::values());
        if (count($kinds) > 1) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('filter[kind] takes one value: contribution or drawing.');
        }

        return [$f->id('holder_id'), $kinds === [] ? null : CapitalEntryKind::from($kinds[0]), $f->date('from'), $f->date('to')];
    }
}
