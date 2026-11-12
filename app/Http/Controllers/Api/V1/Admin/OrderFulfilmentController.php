<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\BulkMarkOrdersDelivered;
use App\Domain\Ordering\Actions\BulkMarkOrdersShipped;
use App\Domain\Ordering\Actions\MarkOrderDelivered;
use App\Domain\Ordering\Actions\MarkOrderShipped;
use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\BulkFulfilOrdersRequest;
use App\Http\Requests\Admin\Order\DeliverOrderRequest;
use App\Http\Requests\Admin\Order\ShipOrderRequest;
use App\Http\Resources\Admin\OrderBulkResultResource;
use App\Http\Resources\Admin\OrderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Manual fulfilment (P5) until courier booking and status updates land in Phase 6: staff
 * mark an order shipped (stock consumed) and delivered (revenue recognised by Finance,
 * COD collected). Every route needs an `Idempotency-Key` (rule 17) — a double submit
 * replays, it never ships twice. A move the state machine refuses is a 409 `conflict`.
 */
final class OrderFulfilmentController extends ApiController
{
    /**
     * `POST /orders/{order}/ship` — confirmed, processing or ready to ship → shipped. Consumes
     * the stock reservation (`sale_out` movements) in the same transaction; the courier name
     * and tracking reference land on the timeline. Needs orders.update + shipments.book.
     */
    #[OpenApiResponse(409, 'The order cannot ship from its status (`conflict`), or stock no longer covers a line (`insufficient-stock`)')]
    public function ship(ShipOrderRequest $request, Order $order, MarkOrderShipped $ship): OrderResource|JsonResponse
    {
        return OrderProblems::guard($request, fn (): OrderResource => $this->moved($ship->handle(
            $order, $request->courierName(), $request->trackingReference(), $request->user()?->id, $request->note(),
        )));
    }

    /**
     * `POST /orders/{order}/deliver` — shipped → delivered. A pending COD payment is marked
     * paid (the courier holds the cash: it is not cash in the ledger until remittance);
     * Finance recognises revenue from OrderDelivered. Needs orders.update + shipments.book.
     */
    #[OpenApiResponse(409, 'The order is not shipped (`conflict`)')]
    public function deliver(DeliverOrderRequest $request, Order $order, MarkOrderDelivered $deliver): OrderResource
    {
        return $this->moved($deliver->handle($order, $request->user()?->id, $request->note()));
    }

    /**
     * `POST /orders/bulk/ship` — ships each order on its own; one result per requested id
     * (`done`, `conflict` with the current status, `not_found`) and `meta.done` /
     * `meta.failed`. A partial failure is a 200 with per-order results.
     */
    public function bulkShip(BulkFulfilOrdersRequest $request, BulkMarkOrdersShipped $bulk): AnonymousResourceCollection
    {
        return self::results($bulk->handle($request->orderIds(), $request->courierName(), $request->user()?->id));
    }

    /** `POST /orders/bulk/deliver` — delivers each shipped order on its own; per-order results as bulk ship. */
    public function bulkDeliver(BulkFulfilOrdersRequest $request, BulkMarkOrdersDelivered $bulk): AnonymousResourceCollection
    {
        return self::results($bulk->handle($request->orderIds(), $request->user()?->id));
    }

    /** @param  list<BulkOrderOutcome>  $outcomes */
    private static function results(array $outcomes): AnonymousResourceCollection
    {
        $done = count(array_filter($outcomes, static fn (BulkOrderOutcome $o): bool => $o->succeeded()));

        return OrderBulkResultResource::collection($outcomes)
            ->additional(['meta' => ['done' => $done, 'failed' => count($outcomes) - $done]]);
    }

    private function moved(Order $order): OrderResource
    {
        return new OrderResource($order->refresh()->load(OrderResource::RELATIONS));
    }
}
