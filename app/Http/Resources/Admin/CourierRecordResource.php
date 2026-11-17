<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * What the couriers officially report about one phone (CourierHistoryLookup) — verified external
 * data, kept apart from our own score (rule 24). A courier that cannot answer says why, never 0
 * (rule 33b). Carries no phone number and no credential.
 *
 * @property array{checked_at: string, providers: list<array<string, mixed>>} $resource
 */
#[SchemaName('CourierRecord')]
final class CourierRecordResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'checked_at' => $this->resource['checked_at'],
            /** @var list<array{provider: string, name: string, name_bn: string|null, connected: bool, reason: string|null, total_parcels: int|null, total_delivered: int|null, total_cancelled: int|null, total_fraud_reports: int|null, delivery_ratio: string|null, cancellation_ratio: string|null, volume: string|null, fetched_at: string|null}> */
            'providers' => $this->resource['providers'],
        ];
    }
}
