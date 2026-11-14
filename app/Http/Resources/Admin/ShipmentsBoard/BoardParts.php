<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\ShipmentsBoard;

use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Shapes shared by the board's row and tracking resources. The customer phone is E.164 only
 * with customers.view_pii, else masked (rule 20); no address is ever returned.
 */
final class BoardParts
{
    /** @return array{id: int, number: string, customer_name: string, phone: string, phone_masked: bool}|null */
    public static function order(Shipment $s, Request $request): ?array
    {
        $order = $s->getRelationValue('orderSummary');
        if (! $order instanceof Order) {
            return null;
        }
        $user = $request->user();
        $pii = $user instanceof User && $user->can('viewPii', Order::class);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'customer_name' => $order->shipping_name,
            'phone' => $pii ? $order->shipping_phone : $order->maskedShippingPhone(),
            'phone_masked' => ! $pii,
        ];
    }

    /** @return array{amount: string|null, basis: 'settled'|'recorded'|'estimate'|null} */
    public static function cost(Shipment $s): array
    {
        $cost = $s->getRelationValue('boardCost');
        /** @var 'settled'|'recorded'|'estimate'|null $basis */
        $basis = is_object($cost) && isset($cost->basis) && is_string($cost->basis) ? $cost->basis : null;

        return [
            'amount' => is_object($cost) && isset($cost->amount) && is_string($cost->amount) ? $cost->amount : null,
            'basis' => $basis,
        ];
    }

    /** @return array{id: int, status: string, provider_status: string|null, message: string|null, location: string|null, occurred_at: string, received_at: string, source: string} */
    public static function event(CourierTrackingEvent $e): array
    {
        return [
            'id' => $e->id,
            'status' => $e->normalised_status->value,
            'provider_status' => $e->provider_status,
            'message' => $e->message,
            'location' => $e->location,
            'occurred_at' => $e->occurred_at->toIso8601String(),
            'received_at' => $e->received_at->toIso8601String(),
            'source' => $e->source,
        ];
    }
}
