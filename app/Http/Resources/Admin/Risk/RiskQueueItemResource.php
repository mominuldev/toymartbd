<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A verification-queue row (Figma B · 05 241:36206): the held order, the score it was held
 * on (`order_risk`), our profile of the phone (`ours`, `status`) and the call log summary.
 * No courier data on the list — it is fetched per order on the detail, on demand (rule 4).
 *
 * @property HeldOrder $resource
 */
final class RiskQueueItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $held = $this->resource;
        $order = $held->order;
        $user = $request->user();

        return [
            'id' => $order->id,
            'number' => $order->number,
            /** @var string|null */
            'placed_at' => $order->placed_at?->toIso8601String(),
            'grand_total' => $order->grand_total->toDecimal(),
            'payment_method' => $order->payment_method,
            'customer_name' => $order->shipping_name,
            'phone' => RiskProfilePresenter::phone($order->shipping_phone, $user instanceof User ? $user : null),
            'district' => $order->shipping_district,
            'order_risk' => RiskProfilePresenter::orderRisk($order),
            'ours' => RiskProfilePresenter::ours($held->profile),
            'status' => RiskProfilePresenter::status($held->profile),
            'calls' => [
                'count' => $held->callCount,
                /** @var string|null */
                'last_outcome' => $held->lastCallOutcome,
            ],
        ];
    }
}
