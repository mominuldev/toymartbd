<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\ShipmentsBoard;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Delivery issues, last 30 Dhaka days (docs/api/shipments-board.md): parcels that came back
 * (with what they cost as recorded on the shipments), shipments with a failed attempt,
 * bookings blocked by an unmapped / ambiguous courier area (rule 32), and delivery success
 * by courier × area. A bar appears only where a parcel had an outcome.
 *
 * @property array{from: string, to: string, returned: array{count: int, cost: string, districts: list<string>}, failed_attempts: array{count: int, latest_provider_status: string|null}, unmapped: array{count: int, areas: list<string>}, by_courier: list<array{provider: array{code: string, name: string, name_bn: string|null}, area: string, delivered: int, returned: int, rate: string}>} $resource
 */
#[SchemaName('ShipmentBoardIssues')]
final class ShipmentBoardIssuesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'from' => $r['from'],
            'to' => $r['to'],
            /** @var array{count: int, cost: string, districts: list<string>} */
            'returned' => $r['returned'],
            /** @var array{count: int, latest_provider_status: string|null} */
            'failed_attempts' => $r['failed_attempts'],
            /** @var array{count: int, areas: list<string>} */
            'unmapped' => $r['unmapped'],
            /** @var list<array{provider: array{code: string, name: string, name_bn: string|null}, area: 'dhaka'|'outside'|'unknown', delivered: int, returned: int, rate: string}> */
            'by_courier' => $r['by_courier'],
        ];
    }
}
