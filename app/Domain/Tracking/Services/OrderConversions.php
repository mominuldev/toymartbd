<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingEventRecord;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Support\TrackingEventIds;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Builds the server-originated order conversions (PRD K-02): `purchase` (value = order grand
 * total, items = order lines, BDT, transaction_id = order number, event_id = UUIDv5 of
 * `purchase:{number}`) and `refund` (GA4 only). Reads the order; never writes it.
 */
final readonly class OrderConversions
{
    public function __construct(private DispatchPolicy $policy) {}

    public function purchase(Order $order, CarbonImmutable $occurredAt): TrackingEventRecord
    {
        $order->loadMissing('items');

        return new TrackingEventRecord(
            eventId: TrackingEventIds::purchase($order->number),
            name: TrackingEventName::Purchase,
            source: TrackingSource::Server,
            occurredAt: $occurredAt,
            consent: $this->consentFor($order),
            value: $order->grand_total->toDecimal(),
            currency: $order->currency !== '' ? $order->currency : 'BDT',
            items: self::items($order),
            transactionId: $order->number,
            orderId: $order->id,
            customerId: $order->customer_id,
        );
    }

    public function refund(Order $order, int $refundId, string $amount, CarbonImmutable $occurredAt): TrackingEventRecord
    {
        return new TrackingEventRecord(
            eventId: TrackingEventIds::refund($refundId),
            name: TrackingEventName::Refund,
            source: TrackingSource::Server,
            occurredAt: $occurredAt,
            consent: $this->consentFor($order),
            value: Money::of($amount)->toDecimal(),
            currency: $order->currency !== '' ? $order->currency : 'BDT',
            transactionId: $order->number,
            orderId: $order->id,
            customerId: $order->customer_id,
        );
    }

    /**
     * The consent the shopper gave at checkout, or — with none on record (staff-entered
     * order, or a checkout that sent no `tracking`) — what the policy allows server-side.
     */
    public function consentFor(Order $order): ConsentState
    {
        $context = OrderTrackingContext::query()->where('order_id', $order->id)->first();

        return DispatchPolicy::consentFromArray($context?->consent) ?? $this->policy->serverConsent();
    }

    /** @return list<array{item_id:string,item_name:string,price:string,quantity:int,item_variant?:string}> */
    private static function items(Order $order): array
    {
        return $order->items->map(static function (OrderItem $item): array {
            $line = [
                'item_id' => $item->sku,
                'item_name' => $item->product_name,
                'price' => $item->unit_price->toDecimal(),
                'quantity' => $item->quantity,
            ];
            if ($item->variant_name !== null && $item->variant_name !== '') {
                $line['item_variant'] = $item->variant_name;
            }

            return $line;
        })->values()->all();
    }
}
