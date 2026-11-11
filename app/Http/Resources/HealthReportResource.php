<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Health\HealthReport;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * @property HealthReport $resource
 */
final class HealthReportResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->healthy() ? 'ok' : 'degraded',
            'checks' => array_map(
                static fn (array $check): array => [...$check, 'status' => $check['status']->value],
                $this->resource->checks,
            ),
            // Courier sync lands in Phase 6 (Fulfilment). Always null until then, and never
            // counted towards `status`.
            'last_courier_sync' => null,
            'checked_at' => $this->resource->checkedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
