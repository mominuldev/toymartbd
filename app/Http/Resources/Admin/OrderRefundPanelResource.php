<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Services\ReturnQuantities;
use App\Domain\Payment\Models\Refund;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Refund panel on order detail: the order's returns and refunds, and — computed by the
 * server, never the browser (rule 1) — how many units of each line can still come back,
 * what was captured and what is still refundable. Money only with refunds.view.
 *
 * @property Order $resource
 */
#[SchemaName('OrderRefundPanel')]
final class OrderRefundPanelResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $quantities = app(ReturnQuantities::class);
        $returnable = $quantities->returnable($order);
        $user = $request->user();
        $seesMoney = $user?->can('viewAny', Refund::class) ?? false;

        $returns = OrderReturn::query()->where('order_id', $order->id)->with(OrderReturnResource::RELATIONS)->orderByDesc('id')->get();
        $refunds = $seesMoney
            ? Refund::query()->where('order_id', $order->id)->with(['items', 'payment'])->orderByDesc('id')->get()
            : collect();

        return [
            'order_id' => $order->id,
            'accepts_returns' => in_array($order->status, [OrderStatus::Delivered, OrderStatus::PartiallyReturned], true),
            /** @var bool */
            'shipping_claimed' => $quantities->shippingClaimed($order->id),
            /** @var string|null */
            'captured' => $seesMoney ? $quantities->captured($order->id)->toDecimal() : null,
            /** @var string|null */
            'refundable' => $seesMoney ? $quantities->refundable($order->id)->toDecimal() : null,
            'lines' => $order->items->map(static fn (OrderItem $item): array => [
                'order_item_id' => $item->id,
                'product_name' => $item->product_name,
                /** @var string|null */
                'product_name_bn' => $item->product_name_bn,
                /** @var string|null */
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'quantity_returned' => $item->quantity_returned,
                /** @var int */
                'returnable' => $returnable[$item->id] ?? 0,
            ])->values()->all(),
            'returns' => OrderReturnResource::collection($returns),
            'refunds' => RefundResource::collection($refunds),
            'can' => [
                'create_return' => $user?->can('create', OrderReturn::class) ?? false,
            ],
        ];
    }
}
