<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Courier performance for a period of Dhaka dates (CourierPerformanceReport, P6). Every
 * figure is the server's: counts are integers, rates and days decimal strings (null when
 * nothing to divide by), money decimal strings (rule 6). Definitions: docs/api/courier-tracking.md.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('CourierPerformance')]
final class CourierPerformanceResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string Dhaka date, inclusive. */
            'from' => $r['from'],
            /** @var string Dhaka date, inclusive. */
            'to' => $r['to'],
            /** @var string|null The provider filter, or null for every courier. */
            'provider' => $r['provider'],
            /** @var array{booked: int, delivered: int, partially_delivered: int, returned: int, cancelled: int, in_flight: int, delivery_rate: string|null, return_rate: string|null, average_days_to_deliver: string|null, average_charge: string|null, cod_expected: string, cod_collected: string, cod_outstanding: string} */
            'totals' => $r['totals'],
            /** @var list<array{provider: array{id: int, code: string, name: string, name_bn: string|null}, booked: int, delivered: int, partially_delivered: int, returned: int, cancelled: int, in_flight: int, delivery_rate: string|null, return_rate: string|null, average_days_to_deliver: string|null, average_charge: string|null, cod_expected: string, cod_collected: string, cod_outstanding: string}> */
            'providers' => $r['providers'],
            /**
             * `district` null = shipments whose order has no district.
             *
             * @var list<array{district: array{id: int, name: string, name_bn: string|null}|null, booked: int, delivered: int, partially_delivered: int, returned: int, cancelled: int, in_flight: int, delivery_rate: string|null, return_rate: string|null, average_days_to_deliver: string|null, average_charge: string|null, cod_expected: string, cod_collected: string, cod_outstanding: string}>
             */
            'districts' => $r['districts'],
        ];
    }
}
