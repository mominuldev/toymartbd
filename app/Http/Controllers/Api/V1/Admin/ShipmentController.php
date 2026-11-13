<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Actions\CancelShipment;
use App\Domain\Fulfilment\Actions\RecordShipmentRider;
use App\Domain\Fulfilment\Actions\RequestShipment;
use App\Domain\Fulfilment\Actions\RequestShipments;
use App\Domain\Fulfilment\Actions\RetryShipment;
use App\Domain\Fulfilment\DTOs\ShipmentRequestOutcome;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Shipment\BulkRequestShipmentsRequest;
use App\Http\Requests\Admin\Shipment\CancelShipmentRequest;
use App\Http\Requests\Admin\Shipment\RequestShipmentRequest;
use App\Http\Requests\Admin\Shipment\RetryShipmentRequest;
use App\Http\Requests\Admin\Shipment\UpdateShipmentRiderRequest;
use App\Http\Resources\Admin\ShipmentBulkResultResource;
use App\Http\Resources\Admin\ShipmentResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Courier shipments (P6, docs/api/shipments.md). Booking runs on the `courier` queue after
 * the request commits, so a request answers 202 with the shipment `pending` (or already
 * `booked` when the queue is synchronous). Every write needs an `Idempotency-Key`
 * (rule 17). A move the shipment or order state refuses is a 409 `conflict`.
 */
final class ShipmentController extends ApiController
{
    public const int PER_PAGE = 25;

    /**
     * `GET /shipments` — cursor-paginated, needs shipments.view.
     * Filters: `filter[status]`, `filter[provider]` (code), `filter[order_id]`,
     * `filter[needs_attention]=1`. Sorts: `created_at`, `booked_at` (prefix `-` for
     * descending; default `-created_at`). Anything else is a 400.
     */
    #[QueryParameter('filter[status]', 'Only shipments in this status.', type: 'string')]
    #[QueryParameter('filter[provider]', 'Only this courier provider code.', type: 'string')]
    #[QueryParameter('filter[order_id]', 'Only this order\'s shipments.', type: 'int')]
    #[QueryParameter('filter[needs_attention]', '`1`: only the retry queue.', type: 'string')]
    #[QueryParameter('sort', 'Sort field; prefix `-` for descending.', type: "'created_at'|'-created_at'|'booked_at'|'-booked_at'", default: '-created_at')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        return $this->list($request, false);
    }

    /** `GET /shipments/failed` — the retry queue (failed, or booked but not dispatched). Needs shipments.view. */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    public function failed(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        return $this->list($request, true);
    }

    /** `GET /shipments/{shipment}` — needs shipments.view. */
    public function show(Shipment $shipment): ShipmentResource
    {
        $this->authorize('view', $shipment);

        return self::resource($shipment);
    }

    /**
     * `POST /orders/{order}/shipments` — request a courier booking for one order. 202 with the
     * shipment. Needs orders.update + shipments.book.
     */
    #[OpenApiResponse(409, 'The order is not confirmed/processing/ready to ship, or already has an open shipment (`conflict`)')]
    #[OpenApiResponse(422, 'Unknown or inactive provider, or a location of another provider')]
    public function store(RequestShipmentRequest $request, Order $order, RequestShipment $action): JsonResponse
    {
        $shipment = $action->handle(
            $order->id,
            $request->provider(),
            $request->courierLocationId(),
            $request->weightGrams(),
            $request->note(),
            $request->user()?->id,
        );

        return self::resource($shipment->refresh())->response()->setStatusCode(202);
    }

    /**
     * `POST /shipments/bulk` — request bookings for many orders; one result per id and
     * `meta.requested` / `meta.failed`. A partial failure is a 200 with per-order results.
     */
    public function bulk(BulkRequestShipmentsRequest $request, RequestShipments $action): AnonymousResourceCollection
    {
        $outcomes = $action->handle($request->orderIds(), $request->provider(), $request->user()?->id);
        $requested = count(array_filter($outcomes, static fn (ShipmentRequestOutcome $o): bool => $o->succeeded()));

        return ShipmentBulkResultResource::collection($outcomes)
            ->additional(['meta' => ['requested' => $requested, 'failed' => count($outcomes) - $requested]]);
    }

    /** `POST /shipments/{shipment}/retry` — failed → pending and re-queued; completion_failed → completed locally. */
    #[OpenApiResponse(409, 'The shipment is not retryable (`conflict`)')]
    public function retry(RetryShipmentRequest $request, Shipment $shipment, RetryShipment $action): ShipmentResource
    {
        return self::resource($action->handle($shipment, $request->user()?->id));
    }

    /** `POST /shipments/{shipment}/cancel` — only pending or failed. Needs shipments.cancel. */
    #[OpenApiResponse(409, 'A courier already holds the parcel (`conflict`)')]
    public function cancel(CancelShipmentRequest $request, Shipment $shipment, CancelShipment $action): ShipmentResource
    {
        return self::resource($action->handle($shipment, $request->reason()));
    }

    /**
     * `PATCH /shipments/{shipment}/rider` — record (or clear, both null) the delivery rider the
     * courier sent, when the rider calls. Needs shipments.book; audited with the phone masked.
     * The phone comes back in full only with customers.view_pii. Never resends a message.
     */
    #[OpenApiResponse(409, 'The shipment is pending, failed or cancelled — no courier holds it (`conflict`)')]
    public function rider(UpdateShipmentRiderRequest $request, Shipment $shipment, RecordShipmentRider $action): ShipmentResource
    {
        return self::resource($action->handle($shipment, $request->riderName(), $request->riderPhone(), $request->user()?->id));
    }

    private function list(Request $request, bool $needsAttention): AnonymousResourceCollection
    {
        $sort = $request->query('sort');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $base = Shipment::query();
        if ($needsAttention) {
            $base->needsAttention();
        }

        $query = QueryBuilder::for($base, $request)
            ->allowedFilters(...self::filters())
            ->allowedSorts('created_at', 'booked_at')
            ->defaultSort('-created_at')
            ->orderBy('id', $descending ? 'desc' : 'asc')
            ->with('provider');

        /** @var CursorPaginator<int, Shipment> $page */
        $page = $query->cursorPaginate(self::PER_PAGE)->withQueryString();
        self::attachOrders(collect($page->items()));

        return ShipmentResource::collection($page);
    }

    /** @return list<AllowedFilter> */
    private static function filters(): array
    {
        return [
            AllowedFilter::callback('status', static function (Builder $q, mixed $value): void {
                $status = is_string($value) ? ShipmentStatus::tryFrom($value) : null;
                if ($status === null) {
                    throw new BadRequestHttpException('Unknown shipment status filter.');
                }
                $q->where('status', $status->value);
            }),
            AllowedFilter::callback('provider', static function (Builder $q, mixed $value): void {
                if (! is_string($value)) {
                    throw new BadRequestHttpException('Invalid provider filter.');
                }
                $q->whereHas('provider', static fn (Builder $p) => $p->where('code', $value));
            }),
            AllowedFilter::callback('order_id', static function (Builder $q, mixed $value): void {
                if (! is_numeric($value)) {
                    throw new BadRequestHttpException('Invalid order_id filter.');
                }
                $q->where('order_id', (int) $value);
            }),
            AllowedFilter::callback('needs_attention', static function (Builder $q, mixed $value): void {
                if (in_array($value, ['1', 1, true, 'true'], true)) {
                    $q->whereIn('id', Shipment::query()->needsAttention()->select('id'));
                }
            }),
        ];
    }

    private static function resource(Shipment $shipment): ShipmentResource
    {
        $shipment->loadMissing('provider');
        self::attachOrders(collect([$shipment]));

        return new ShipmentResource($shipment);
    }

    /**
     * Fulfilment has no order relation (deptrac): the HTTP layer attaches the summary.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    private static function attachOrders(Collection $shipments): void
    {
        $orders = Order::query()->whereIn('id', $shipments->pluck('order_id')->unique())->get(['id', 'number', 'status'])->keyBy('id');
        foreach ($shipments as $shipment) {
            $shipment->setRelation('orderSummary', $orders[$shipment->order_id] ?? null);
        }
    }
}
