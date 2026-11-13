<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The mapping screen's header: the provider, totals, the last sync, and the courier's
 * cities (for the city filter) with how much of each is mapped.
 *
 * @property array{provider: array{id: int, code: string, name: string, name_bn: string|null}, zones: int, areas: int, mapped: int, mapped_inactive: int, last_sync: array{cities: int, zones: int, areas: int, created: int, deactivated: int, mapped_inactive: int, finished_at: string}|null, cities: list<array{id: string, name: string, zones: int, mapped: int}>} $resource
 */
#[SchemaName('CourierLocationOverview')]
final class CourierLocationOverviewResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            /** @var array{id: int, code: string, name: string, name_bn: string|null} */
            'provider' => $o['provider'],
            /** @var int */
            'zones' => $o['zones'],
            /** @var int */
            'areas' => $o['areas'],
            /**
             * Active rows a human has mapped.
             *
             * @var int
             */
            'mapped' => $o['mapped'],
            /**
             * Mapped rows the courier no longer serves — bookings there are blocked until re-mapped.
             *
             * @var int
             */
            'mapped_inactive' => $o['mapped_inactive'],
            /** @var array{cities: int, zones: int, areas: int, created: int, deactivated: int, mapped_inactive: int, finished_at: string}|null */
            'last_sync' => $o['last_sync'],
            /** @var list<array{id: string, name: string, zones: int, mapped: int}> */
            'cities' => $o['cities'],
        ];
    }
}
