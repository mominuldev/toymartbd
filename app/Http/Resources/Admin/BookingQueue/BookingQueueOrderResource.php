<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\BookingQueue;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\Admin\BookingQueue\BookingQueueReadModel;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One ready-to-ship order in the booking queue. No phone and no street address (rule 20) —
 * only the recipient name and the area. `couriers[].blocked_reason` says why that courier
 * cannot take the order (null = it can); `suggested_provider_id` is the default courier when
 * it can, else the first that can, else null (the row cannot be booked by anyone yet).
 *
 * @property array{order: Order, district: District|null, upazila: Upazila|null, zone: string|null, weight_grams: int, weight_missing: bool, item_count: int, couriers: list<array{provider_id: int, location_status: string, blocked_reason: string|null}>, suggested_provider_id: int|null} $resource
 */
#[SchemaName('BookingQueueOrder')]
final class BookingQueueOrderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $order = $r['order'];
        $place = static fn (District|Upazila|null $m): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->name, 'name_bn' => $m->name_bn];

        return [
            /** @var int */
            'order_id' => $order->id,
            'number' => $order->number,
            /** @var string|null When the order was packed (entered ready_to_ship). */
            'ready_since' => $order->packed_at?->toIso8601String(),
            'recipient_name' => $order->shipping_name,
            /** @var array{id: int, name: string, name_bn: string}|null */
            'district' => $place($r['district']),
            /** @var array{id: int, name: string, name_bn: string}|null */
            'upazila' => $place($r['upazila']),
            /** @var 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka'|null */
            'zone' => $r['zone'],
            /** @var int Sum of variant weights × quantity (what bulk booking sends). */
            'weight_grams' => $r['weight_grams'],
            /** @var bool A line has no variant weight: the parcel would be understated. */
            'weight_missing' => $r['weight_missing'],
            /** @var int */
            'item_count' => $r['item_count'],
            /** @var string What the courier collects: the order's due total, never negative. */
            'cod_amount' => BookingQueueReadModel::cod($order)->toDecimal(),
            /** @var list<array{provider_id: int, location_status: 'not_required'|'mapped'|'ambiguous'|'unmapped', blocked_reason: 'inactive'|'not_connected'|'unverified_in_production'|'missing_address'|'not_serviced'|'unmapped_location'|'ambiguous_location'|'missing_weight'|null}> */
            'couriers' => $r['couriers'],
            /** @var int|null */
            'suggested_provider_id' => $r['suggested_provider_id'],
        ];
    }
}
