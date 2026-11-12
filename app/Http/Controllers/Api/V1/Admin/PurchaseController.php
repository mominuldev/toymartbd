<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Purchasing\Actions\CancelPurchase;
use App\Domain\Purchasing\Actions\ClosePurchaseShort;
use App\Domain\Purchasing\Actions\CreatePurchase;
use App\Domain\Purchasing\Actions\PlacePurchaseOrder;
use App\Domain\Purchasing\Actions\UpdatePurchase;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Purchasing\ClosePurchaseRequest;
use App\Http\Requests\Admin\Purchasing\StorePurchaseRequest;
use App\Http\Requests\Admin\Purchasing\UpdatePurchaseRequest;
use App\Http\Resources\Admin\PurchaseListItemResource;
use App\Http\Resources\Admin\PurchaseResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Purchase orders (P5, PRD I-04). Never deleted: a PO that will not happen is
 * cancelled. Lifecycle moves are separate endpoints, each a 409 when the state machine
 * refuses it.
 */
final class PurchaseController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /purchases` — cursor-paginated, newest first; needs purchasing.view.
     *
     * Filters: `filter[status]` (one PurchaseStatus), `filter[supplier_id]`, `filter[search]`
     * (PO number, supplier invoice number or supplier name contains). Sorts: `created_at`,
     * `-created_at` (default), `expected_date`, `-expected_date`. Anything else is a 400.
     */
    #[QueryParameter('filter[status]', 'One PO status.', type: "'draft'|'ordered'|'partially_received'|'received'|'cancelled'|'closed_short'")]
    #[QueryParameter('filter[supplier_id]', 'Only this supplier\'s POs.', type: 'int')]
    #[QueryParameter('filter[search]', 'PO number, supplier invoice number or supplier name contains.', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'created_at'|'-created_at'|'expected_date'|'-expected_date'", default: '-created_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Purchase::class);

        $sort = $request->query('sort', '-created_at');
        $descending = is_string($sort) && str_starts_with($sort, '-');

        $purchases = QueryBuilder::for(Purchase::query()->with('supplier')->withCount('items'), $request)
            ->allowedFilters(
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $status = is_string($value) ? PurchaseStatus::tryFrom($value) : null;
                    if ($status === null) {
                        throw new BadRequestHttpException('filter[status] must be one of: '.implode(', ', PurchaseStatus::values()).'.');
                    }
                    $query->where('status', $status->value);
                }),
                AllowedFilter::callback('supplier_id', static function (Builder $query, mixed $value): void {
                    if (! is_string($value) || ! ctype_digit($value)) {
                        throw new BadRequestHttpException('filter[supplier_id] must be an integer.');
                    }
                    $query->where('supplier_id', (int) $value);
                }),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('number', 'like', $like)
                        ->orWhere('supplier_invoice_no', 'like', $like)
                        ->orWhereHas('supplier', static fn (Builder $s) => $s->where('name', 'like', $like)));
                }),
            )
            ->allowedSorts('created_at', 'expected_date')
            ->defaultSort('-created_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return PurchaseListItemResource::collection($purchases);
    }

    /**
     * `GET /purchases/counts` — POs per status, for the list's tabs; needs purchasing.view.
     *
     * @return array{draft: int, ordered: int, partially_received: int, received: int, cancelled: int, closed_short: int}
     */
    public function counts(): array
    {
        $this->authorize('viewAny', Purchase::class);

        $counts = Purchase::query()->groupBy('status')->selectRaw('status, COUNT(*) as aggregate')->pluck('aggregate', 'status');

        return [
            'draft' => (int) ($counts['draft'] ?? 0),
            'ordered' => (int) ($counts['ordered'] ?? 0),
            'partially_received' => (int) ($counts['partially_received'] ?? 0),
            'received' => (int) ($counts['received'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'closed_short' => (int) ($counts['closed_short'] ?? 0),
        ];
    }

    /** `POST /purchases` — raises a draft PO; needs purchasing.create. */
    public function store(StorePurchaseRequest $request, CreatePurchase $create): JsonResponse
    {
        $purchase = $create->handle($request->toData(), $request->user()?->id);

        return (new PurchaseResource(self::loaded($purchase)))->response()->setStatusCode(201);
    }

    /** `GET /purchases/{purchase}` — lines and receipts; needs purchasing.view. */
    public function show(Purchase $purchase): PurchaseResource
    {
        $this->authorize('view', $purchase);

        return new PurchaseResource(self::loaded($purchase));
    }

    /** `PUT /purchases/{purchase}` — replaces a draft; 409 once ordered. Needs purchasing.create. */
    #[OpenApiResponse(409, 'Not a draft (`conflict`)')]
    public function update(UpdatePurchaseRequest $request, Purchase $purchase, UpdatePurchase $update): PurchaseResource
    {
        return new PurchaseResource(self::loaded($update->handle($purchase, $request->toData())));
    }

    /** `POST /purchases/{purchase}/order` — draft → ordered; 409 otherwise. Needs purchasing.create. */
    #[OpenApiResponse(409, 'Not a draft (`conflict`)')]
    public function place(Purchase $purchase, PlacePurchaseOrder $place): PurchaseResource
    {
        $this->authorize('place', $purchase);

        return new PurchaseResource(self::loaded($place->handle($purchase)));
    }

    /** `POST /purchases/{purchase}/cancel` — draft/ordered → cancelled; 409 once stock was received. Needs purchasing.create. */
    #[OpenApiResponse(409, 'Already received against, or already closed (`conflict`)')]
    public function cancel(Purchase $purchase, CancelPurchase $cancel): PurchaseResource
    {
        $this->authorize('cancel', $purchase);

        return new PurchaseResource(self::loaded($cancel->handle($purchase)));
    }

    /**
     * `POST /purchases/{purchase}/close` — partially received → closed short, with a reason;
     * nothing more can be received and nothing is owed for the missing units. 409 unless
     * partially received. Needs purchasing.create.
     */
    #[OpenApiResponse(409, 'Not partially received (`conflict`)')]
    public function close(ClosePurchaseRequest $request, Purchase $purchase, ClosePurchaseShort $close): PurchaseResource
    {
        return new PurchaseResource(self::loaded($close->handle($purchase, $request->reason(), $request->user()?->id)));
    }

    /** @internal shared with PurchaseReceiptController */
    public static function loaded(Purchase $purchase): Purchase
    {
        return $purchase->load(['supplier', 'items.variant.product.images', 'receipts.items']);
    }
}
