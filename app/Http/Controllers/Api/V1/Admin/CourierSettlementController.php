<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Finance\Actions\ConfirmCourierSettlement;
use App\Domain\Finance\Actions\ImportCourierSettlement;
use App\Domain\Finance\Actions\ReviewSettlementLine;
use App\Domain\Finance\Enums\SettlementReview;
use App\Domain\Finance\Enums\SettlementStatus;
use App\Domain\Finance\Enums\SettlementVariance;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use App\Domain\Finance\Settlement\StatementReader;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Finance\ConfirmSettlementRequest;
use App\Http\Requests\Admin\Finance\ImportSettlementRequest;
use App\Http\Requests\Admin\Finance\ReviewSettlementLineRequest;
use App\Http\Resources\Admin\CourierSettlementResource;
use Closure;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * COD settlements (Figma B · 09 241:35325, DATABASE-DESIGN §4.5): import a courier remittance,
 * review each variance, confirm. Nothing is booked until a person confirms; nothing is ever
 * adjusted automatically. The manual "Record COD received" stays for one-offs.
 */
final class CourierSettlementController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /finance/settlements` — newest first; needs finance.view. `filter[status]` is the
     * statement's review state as the list shows it: `received` (to confirm — every line matches
     * or has a decision), `disputed` (a line that does not match still waits for a decision) or
     * `reconciled`. `filter[provider]` is a courier code from `meta.providers`; an unknown code is
     * a 400. `meta.providers` lists the couriers (code, name) for the import form and the filter;
     * `meta.disputed_count` is how many statements wait for a decision (all couriers, unfiltered).
     */
    #[QueryParameter('filter[status]', 'received (to confirm) | disputed (needs a decision) | reconciled', type: "'received'|'disputed'|'reconciled'")]
    #[QueryParameter('filter[provider]', 'Courier code (steadfast, pathao…), one of `meta.providers`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter, an unknown status or an unknown courier code (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourierSettlement::class);

        // The couriers a statement can come from, for the import form and the filter (finance staff cannot read courier settings).
        $providers = DB::table('courier_providers')->orderBy('position')->orderBy('id')->get(['code', 'name', 'name_bn'])
            ->map(static fn (object $p): array => ['code' => (string) $p->code, 'name' => (string) $p->name, 'name_bn' => is_string($p->name_bn) ? $p->name_bn : null])->all();
        $codes = array_column($providers, 'code');

        $page = QueryBuilder::for(CourierSettlement::class, $request)
            ->allowedFilters(
                AllowedFilter::callback('status', static function (Builder $q, mixed $v): void {
                    match ($v) {
                        'reconciled' => $q->where('status', SettlementStatus::Reconciled->value),
                        'disputed' => $q->where('status', '!=', SettlementStatus::Reconciled->value)->whereExists(self::unresolvedLines()),
                        'received' => $q->where('status', '!=', SettlementStatus::Reconciled->value)->whereNotExists(self::unresolvedLines()),
                        default => throw new BadRequestHttpException('filter[status] must be one of: received, disputed, reconciled.'),
                    };
                }),
                AllowedFilter::callback('provider', static function (Builder $q, mixed $v) use ($codes): void {
                    if (! is_string($v) || ! in_array($v, $codes, true)) {
                        throw new BadRequestHttpException('filter[provider] must be one of: '.implode(', ', $codes).'.');
                    }
                    $q->whereIn('provider_id', DB::table('courier_providers')->where('code', $v)->select('id'));
                }),
            )
            ->orderByDesc('received_at')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        $disputed = CourierSettlement::query()->where('status', '!=', SettlementStatus::Reconciled->value)->whereExists(self::unresolvedLines())->count();

        return CourierSettlementResource::collection($page)->additional(['meta' => ['providers' => $providers, 'disputed_count' => $disputed]]);
    }

    /** A line of the outer settlement that does not match and has no decision yet (as `CourierSettlement::unresolvedCount`). */
    private static function unresolvedLines(): Closure
    {
        return static function (QueryBuilderContract $q): void {
            $q->selectRaw('1')->from('courier_settlement_items')
                ->whereColumn('courier_settlement_items.settlement_id', 'courier_settlements.id')
                ->where('variance_type', '!=', SettlementVariance::None->value)
                ->where('review_status', SettlementReview::Pending->value);
        };
    }

    /** `GET /finance/settlements/{settlement}` — with every line. Needs finance.view. */
    public function show(CourierSettlement $settlement): CourierSettlementResource
    {
        $this->authorize('view', $settlement);

        return new CourierSettlementResource($settlement->load(['items' => static fn ($q) => $q->orderByRaw('line_no IS NULL')->orderBy('line_no')->orderBy('id')]));
    }

    /** `POST /finance/settlements` — import (file or typed lines) and match. Books nothing. Needs finance.reconcile_cod. */
    #[OpenApiResponse(409, 'This courier reference was already imported (`conflict`)')]
    public function store(ImportSettlementRequest $request, ImportCourierSettlement $import, StatementReader $reader): JsonResponse
    {
        $file = $request->statement();
        $path = null;
        if ($file !== null) {
            $lines = $reader->read((string) $file->getRealPath(), $request->statementFormat());
            $stored = $file->store('finance/settlements', MediaUrls::originalsDisk());
            $path = is_string($stored) ? $stored : null;
            $source = $request->statementFormat();
        } else {
            $lines = $request->manualLines();
            $source = 'manual';
        }

        $settlement = $import->handle(
            $request->providerCode(), $request->reference(), $request->receivedAt(), $lines, $source,
            $request->periodStart(), $request->periodEnd(), $request->note(), $path, $request->user()?->id,
        );

        return (new CourierSettlementResource($settlement->load('items')))->response()->setStatusCode(201);
    }

    /** `POST /finance/settlements/{settlement}/items/{item}/review` — accept (short only) or exclude. Needs finance.reconcile_cod. */
    #[OpenApiResponse(409, 'Already reconciled, or this variance cannot be accepted (`conflict`)')]
    public function review(ReviewSettlementLineRequest $request, CourierSettlement $settlement, CourierSettlementItem $item, ReviewSettlementLine $review): CourierSettlementResource
    {
        if ($item->settlement_id !== $settlement->id) {
            throw new NotFoundHttpException;
        }
        $review->handle($settlement, $item, $request->decision(), $request->note(), $request->user()?->id);

        return $this->show($settlement->refresh());
    }

    /** `POST /finance/settlements/{settlement}/confirm` — book the matched lines. Needs finance.reconcile_cod and an `Idempotency-Key`. */
    #[OpenApiResponse(409, 'Already reconciled, or a variance line has no decision yet (`conflict`)')]
    public function confirm(ConfirmSettlementRequest $request, CourierSettlement $settlement, ConfirmCourierSettlement $confirm): CourierSettlementResource
    {
        $confirm->handle($settlement, $request->user()?->id);

        return $this->show($settlement->refresh());
    }
}
