<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\ShipmentsBoard;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The board's stat tiles (docs/api/shipments-board.md). `in_transit` and `submitted` are live
 * counts; `success` (delivered ÷ delivered + returned) and `days_to_deliver` (handed over →
 * delivered) count the parcels whose outcome fell in the Dhaka range. A null rate or average
 * means no parcel had the data — the screen shows "—" with that reason.
 *
 * @property array{from: string, to: string, in_transit: array{count: int, cod_on_road: string}, submitted: array{count: int, oldest_at: string|null, oldest_age_minutes: int|null, chase_after_minutes: int, overdue: bool}, success: array{delivered: int, returned: int, rate: string|null}, days_to_deliver: array{average: string|null, timed: int, by_zone: list<array{zone: string, average: string, timed: int}>}} $resource
 */
#[SchemaName('ShipmentBoardStats')]
final class ShipmentBoardStatsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'from' => $r['from'],
            'to' => $r['to'],
            /** @var array{count: int, cod_on_road: string} */
            'in_transit' => $r['in_transit'],
            /** @var array{count: int, oldest_at: string|null, oldest_age_minutes: int|null, chase_after_minutes: int, overdue: bool} */
            'submitted' => $r['submitted'],
            /** @var array{delivered: int, returned: int, rate: string|null} */
            'success' => $r['success'],
            /** @var array{average: string|null, timed: int, by_zone: list<array{zone: 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka', average: string, timed: int}>} */
            'days_to_deliver' => $r['days_to_deliver'],
        ];
    }
}
