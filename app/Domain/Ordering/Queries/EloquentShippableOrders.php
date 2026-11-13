<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfilment\Contracts\ShippableOrders;
use App\Domain\Fulfilment\DTOs\ShippableOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Ordering's side of Fulfilment's read seam: an order reduced to what a courier booking
 * needs. The address line is the free-text address plus area, upazila and district names
 * (Steadfast takes only free text); the provider's own zone ids come from an explicit
 * `courier_locations` mapping, never from these names (rule 32).
 */
final class EloquentShippableOrders implements ShippableOrders
{
    /** Statuses a courier booking may start from (§4.2, the order state machine). */
    public const array SHIPPABLE = [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip];

    public function find(int $orderId): ?ShippableOrder
    {
        $order = Order::query()->with('items')->find($orderId);

        return $order === null ? null : $this->snapshot($order);
    }

    public function lockForShipment(int $orderId): ?ShippableOrder
    {
        $order = Order::query()->lockForUpdate()->find($orderId);
        $order?->load('items');

        return $order === null ? null : $this->snapshot($order);
    }

    private function snapshot(Order $order): ShippableOrder
    {
        $items = $order->items;
        $weights = ProductVariant::query()
            ->whereIn('id', $items->pluck('variant_id')->filter()->unique()->values())
            ->pluck('weight_grams', 'id');

        $weight = 0;
        $count = 0;
        foreach ($items as $item) {
            $count += $item->quantity;
            $weight += (int) ($weights[$item->variant_id] ?? 0) * $item->quantity;
        }

        $description = Str::limit($items->map(static fn (OrderItem $i): string => $i->quantity.'× '.$i->product_name)->implode(', '), 240, '…');
        $address = implode(', ', array_filter([
            $order->shipping_address, $order->shipping_area, $order->shipping_upazila, $order->shipping_district,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== ''));

        return new ShippableOrder(
            id: $order->id,
            number: $order->number,
            status: $order->status->value,
            canShip: in_array($order->status, self::SHIPPABLE, true),
            recipientName: $order->shipping_name,
            phone: $order->shipping_phone,
            addressLine: $address,
            landmark: $order->shipping_landmark,
            districtId: $order->shipping_district_id,
            upazilaId: $order->shipping_upazila_id,
            codAmount: $order->due_total->isNegative() ? Money::zero() : $order->due_total,
            declaredValue: $order->grand_total,
            weightGrams: $weight,
            itemCount: $count,
            itemDescription: $description,
        );
    }
}
