<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Actions\EditOrder;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderQuotes;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Order\UpdateOrderRequest;
use App\Http\Resources\Admin\OrderQuoteResource;
use App\Http\Resources\Admin\OrderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Edit an order after placement (P4-ORD-07): lines, recipient, address; the delivery
 * charge and totals are re-quoted by the pricing pipeline. Editable while pending, on
 * hold, confirmed or processing ({@see EditOrder::EDITABLE}); anything later is a 409.
 * Needs orders.update.
 */
final class OrderEditController extends ApiController
{
    /** `POST /orders/{order}/edit-quote` — what the edit would cost, without writing anything. */
    #[OpenApiResponse(409, 'The order can no longer be edited (`conflict`)')]
    public function quote(UpdateOrderRequest $request, Order $order, EditOrder $edit): OrderQuoteResource
    {
        $data = $request->toData();
        $breakdown = $edit->quote($order->load('items'), $data);

        return new OrderQuoteResource($breakdown, OrderQuotes::blockers($breakdown, $data->upazila, requireCoupon: $order->coupon_code !== null));
    }

    /**
     * `PUT /orders/{order}` — apply the edit. The stock reservation follows the new lines,
     * existing lines keep their cost snapshot, and the timeline gets one `edited` row with
     * the before and after. Returns the order as `GET /orders/{order}` does.
     */
    #[OpenApiResponse(409, 'Not editable in this status or payment in progress (`conflict`), total changed (`price-changed`), or stock ran out (`insufficient-stock`)')]
    #[OpenApiResponse(422, 'Invalid field, unsellable line, coupon lost or address not serviced (`validation-error`)')]
    public function update(UpdateOrderRequest $request, Order $order, EditOrder $edit): OrderResource|JsonResponse
    {
        $result = OrderProblems::guard($request, static fn () => $edit->handle($order, $request->toData(), $request->user()?->id));
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new OrderResource($result->refresh()->load(OrderResource::RELATIONS));
    }
}
