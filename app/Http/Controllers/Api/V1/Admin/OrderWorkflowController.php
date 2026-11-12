<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\BulkConfirmOrders;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\BulkConfirmOrdersRequest;
use App\Http\Requests\Admin\Order\CancelOrderRequest;
use App\Http\Requests\Admin\Order\ConfirmOrderRequest;
use App\Http\Requests\Admin\Order\HoldOrderRequest;
use App\Http\Requests\Admin\Order\OrderWorkflowRequest;
use App\Http\Resources\Admin\OrderBulkResultResource;
use App\Http\Resources\Admin\OrderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The order board's and detail screen's actions (P4-ORD-04/05 write side). Every move goes
 * through the Ordering Actions — workflow moves through {@see TransitionOrder}, cancel through
 * {@see CancelOrder} (releases the reservation) — so the state machine guards each one: a move
 * it does not allow (including a double click or a colleague's earlier move) is a 409
 * `conflict` naming both statuses. Risk never moves an order here; a person does (rule 23).
 * Each returns the order as `GET /orders/{order}` would.
 */
final class OrderWorkflowController extends ApiController
{
    /** `POST /orders/{order}/confirm` — pending or on hold → confirmed. Needs orders.confirm. */
    #[OpenApiResponse(409, 'The order is not pending or on hold any more (`conflict`)')]
    public function confirm(ConfirmOrderRequest $request, Order $order, TransitionOrder $transition): OrderResource
    {
        return $this->moved($transition->handle($order, OrderStatus::Confirmed, $request->user()?->id, $request->note()));
    }

    /** `POST /orders/{order}/hold` — any pre-shipment status → on hold, with a `reason`. Needs orders.update. */
    #[OpenApiResponse(409, 'The order cannot be held from its current status (`conflict`)')]
    public function hold(HoldOrderRequest $request, Order $order, TransitionOrder $transition): OrderResource
    {
        return $this->moved($transition->handle($order, OrderStatus::OnHold, $request->user()?->id, $request->note()));
    }

    /** `POST /orders/{order}/release` — on hold → pending. Needs orders.update. */
    #[OpenApiResponse(409, 'The order is not on hold (`conflict`)')]
    public function release(OrderWorkflowRequest $request, Order $order, TransitionOrder $transition): OrderResource
    {
        return $this->moved($transition->handle($order, OrderStatus::Pending, $request->user()?->id, $request->note()));
    }

    /** `POST /orders/{order}/processing` — confirmed → processing. Needs orders.update. */
    #[OpenApiResponse(409, 'The order is not confirmed (`conflict`)')]
    public function processing(OrderWorkflowRequest $request, Order $order, TransitionOrder $transition): OrderResource
    {
        return $this->moved($transition->handle($order, OrderStatus::Processing, $request->user()?->id, $request->note()));
    }

    /** `POST /orders/{order}/ready-to-ship` — confirmed or processing → ready to ship (packed). Needs orders.update. */
    #[OpenApiResponse(409, 'The order is not confirmed or processing (`conflict`)')]
    public function readyToShip(OrderWorkflowRequest $request, Order $order, TransitionOrder $transition): OrderResource
    {
        return $this->moved($transition->handle($order, OrderStatus::ReadyToShip, $request->user()?->id, $request->note()));
    }

    /**
     * `POST /orders/{order}/cancel` — before shipping only. Releases the stock reservation and
     * cancels open payments in the same transaction. Needs orders.cancel.
     */
    #[OpenApiResponse(409, 'The order has shipped or is already cancelled (`conflict`)')]
    public function cancel(CancelOrderRequest $request, Order $order, CancelOrder $cancel): OrderResource
    {
        return $this->moved($cancel->handle($order, $request->reason(), $request->note(), $request->user()?->id));
    }

    /**
     * `POST /orders/bulk/confirm` — confirms each order on its own; returns one result per
     * requested id (`done`, `conflict` with the current status, or `not_found`) and
     * `meta.done` / `meta.failed`. A partial failure is a 200 with per-order results, never
     * a silent all-or-nothing. Needs orders.confirm.
     */
    public function bulkConfirm(BulkConfirmOrdersRequest $request, BulkConfirmOrders $bulk): AnonymousResourceCollection
    {
        $outcomes = $bulk->handle($request->orderIds(), $request->user()?->id);
        $done = count(array_filter($outcomes, static fn (BulkOrderOutcome $o): bool => $o->succeeded()));

        return OrderBulkResultResource::collection($outcomes)
            ->additional(['meta' => ['done' => $done, 'failed' => count($outcomes) - $done]]);
    }

    private function moved(Order $order): OrderResource
    {
        return new OrderResource($order->refresh()->load(OrderResource::RELATIONS));
    }
}
