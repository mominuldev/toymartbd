<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\BookingQueue;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An area that ready orders ship to and an active courier cannot reach until someone maps
 * it to that courier's own zone (rule 32: never guessed). Fixed on the courier location
 * mapping screen.
 *
 * @property array{provider: CourierProvider, district: District|null, upazila: Upazila|null, district_name: string|null, upazila_name: string|null, orders_count: int, order_numbers: list<string>} $resource
 */
#[SchemaName('BookingQueueUnmappedArea')]
final class BookingQueueUnmappedAreaResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $place = static fn (District|Upazila|null $m): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->name, 'name_bn' => $m->name_bn];

        return [
            /** @var array{id: int, code: string, name: string, name_bn: string|null} */
            'provider' => ['id' => $r['provider']->id, 'code' => $r['provider']->code, 'name' => $r['provider']->name, 'name_bn' => $r['provider']->name_bn],
            /** @var array{id: int, name: string, name_bn: string}|null */
            'district' => $place($r['district']),
            /** @var array{id: int, name: string, name_bn: string}|null Null: a district-level address (e.g. Dhaka city). */
            'upazila' => $place($r['upazila']),
            /** @var string|null The name as written on the orders, when the geography row is missing. */
            'district_name' => $r['district_name'],
            /** @var string|null */
            'upazila_name' => $r['upazila_name'],
            /** @var int */
            'orders_count' => $r['orders_count'],
            /** @var list<string> Up to five order numbers. */
            'order_numbers' => $r['order_numbers'],
        ];
    }
}
