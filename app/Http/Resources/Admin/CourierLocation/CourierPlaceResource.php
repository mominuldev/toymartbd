<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One of OUR places — a district, or an upazila in it — as the mapping screen lists it:
 * in "needs mapping" (orders waiting to ship there, nothing mapped) and in the place picker.
 *
 * @property array{district_id: int, district: string, district_bn: string, upazila_id: int|null, upazila: string|null, upazila_bn: string|null, waiting_orders: int, mapped_count: int} $resource
 */
#[SchemaName('CourierPlace')]
final class CourierPlaceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            /** @var int */
            'district_id' => $p['district_id'],
            /** @var string */
            'district' => $p['district'],
            /** @var string */
            'district_bn' => $p['district_bn'],
            /**
             * Null for a district-level place (an address with no upazila, such as Dhaka city).
             *
             * @var int|null
             */
            'upazila_id' => $p['upazila_id'],
            /** @var string|null */
            'upazila' => $p['upazila'],
            /** @var string|null */
            'upazila_bn' => $p['upazila_bn'],
            /**
             * Open orders (not yet shipped) addressed here — what booking through this courier would block.
             *
             * @var int
             */
            'waiting_orders' => $p['waiting_orders'],
            /**
             * Courier rows a human has mapped to exactly this place.
             *
             * @var int
             */
            'mapped_count' => $p['mapped_count'],
        ];
    }
}
