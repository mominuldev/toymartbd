<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\BookingQueue;

use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\BookingQueue\BookingQueueOrderResource;
use App\Http\Resources\Admin\BookingQueue\BookingQueueShipmentResource;
use App\Http\Resources\Admin\BookingQueue\BookingQueueSummaryResource;
use App\Http\Resources\Admin\BookingQueue\BookingQueueUnmappedAreaResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Courier booking queue (Figma B · 07, docs/api/booking-queue.md). Read-only: every route
 * needs shipments.view. Booking itself goes through the existing endpoints —
 * `POST /orders/{order}/shipments` and `POST /shipments/bulk` (orders.update +
 * shipments.book, Idempotency-Key, queued on `courier` after commit; rules 4, 17).
 */
final class BookingQueueController extends ApiController
{
    /** `GET /shipments/booking-queue` — header facts, courier cards, tab counts. */
    public function summary(BookingQueueReadModel $queue): BookingQueueSummaryResource
    {
        $this->authorize('viewAny', Shipment::class);

        return new BookingQueueSummaryResource($queue->summary());
    }

    /**
     * `GET /shipments/booking-queue/ready` — packed orders with no open shipment, oldest
     * first, cursor-paginated (50). Each row says, per courier, why it cannot be booked.
     * No filters or sorts: any `filter[…]` / `sort` is a 400.
     */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'A filter or sort was sent (`bad-request`)')]
    public function ready(Request $request, BookingQueueReadModel $queue): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        /** @var Paginator<int, Order> $page */
        $page = QueryBuilder::for(BookingQueueReadModel::readyOrders(), $request)
            ->allowedFilters()
            ->allowedSorts()
            ->orderBy('id')
            ->cursorPaginate(BookingQueueReadModel::PER_PAGE)
            ->withQueryString();

        $orders = new EloquentCollection($page->items());
        $rows = $queue->readyRows($orders, $queue->providers());

        return BookingQueueOrderResource::collection($page->setCollection(collect($rows)));
    }

    /**
     * `GET /shipments/booking-queue/shipments?filter[tab]=submitted|booked_today|failed` —
     * newest first, cursor-paginated (50). `submitted` is pending + submitted (no consignment
     * id yet, rule 24d); `booked_today` is booked_at on today's Dhaka date (rule 11);
     * `failed` is the retry queue. A missing or unknown tab, or any other filter, is a 400.
     */
    #[QueryParameter('filter[tab]', 'Which tab.', type: "'submitted'|'booked_today'|'failed'", required: true)]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Missing, unknown or unlisted filter (`bad-request`)')]
    public function shipments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        $tab = $request->input('filter.tab');
        $base = match ($tab) {
            'submitted' => BookingQueueReadModel::awaitingId(),
            'booked_today' => BookingQueueReadModel::bookedToday(),
            'failed' => BookingQueueReadModel::failed(),
            default => throw new BadRequestHttpException('filter[tab] must be submitted, booked_today or failed.'),
        };

        /** @var CursorPaginator<int, Shipment> $page */
        $page = QueryBuilder::for($base, $request)
            ->allowedFilters(AllowedFilter::callback('tab', static fn (Builder $q): Builder => $q))
            ->allowedSorts()
            ->orderByDesc('id')
            ->with('provider')
            ->cursorPaginate(BookingQueueReadModel::PER_PAGE)
            ->withQueryString();

        $shipments = collect($page->items());
        $orders = Order::query()->whereIn('id', $shipments->pluck('order_id')->unique())
            ->get(['id', 'number', 'status', 'shipping_name', 'shipping_district', 'shipping_upazila'])->keyBy('id');
        foreach ($shipments as $shipment) {
            $order = $orders[$shipment->order_id] ?? null;
            $shipment->setRelation('orderSummary', $order);
            $shipment->setRelation('queueOrder', $order);
        }

        return BookingQueueShipmentResource::collection($page);
    }

    /** `GET /shipments/booking-queue/unmapped` — areas an active courier cannot reach until mapped (rule 32). */
    public function unmapped(BookingQueueReadModel $queue): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        return BookingQueueUnmappedAreaResource::collection($queue->unmapped());
    }
}
