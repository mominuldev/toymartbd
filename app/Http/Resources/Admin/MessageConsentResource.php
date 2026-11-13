<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\MessageConsent;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A customer's purpose-specific message consents (P8), for staff. No row = not consented.
 *
 * @property MessageConsent|null $resource
 */
#[SchemaName('MessageConsents')]
final class MessageConsentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $by = $row?->recorded_by === null ? null : User::query()->find($row->recorded_by);

        return [
            /**
             * `consented_at` is when it was last given (kept after a withdrawal); `source` and
             * `recorded_by` describe the latest change — `recorded_by` is null when the customer
             * changed it themselves on the storefront.
             *
             * @var array{consented: bool, consented_at: string|null, withdrawn_at: string|null, source: 'admin'|'storefront'|null, recorded_by: array{id: int, name: string}|null}
             */
            'birthday' => [
                'consented' => $row !== null && $row->consented,
                'consented_at' => $row?->consented_at?->toIso8601String(),
                'withdrawn_at' => $row?->withdrawn_at?->toIso8601String(),
                'source' => $row?->source,
                'recorded_by' => $by === null ? null : ['id' => $by->id, 'name' => $by->name],
            ],
        ];
    }
}
