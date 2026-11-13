<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\MessageConsent;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A customer's purpose-specific message consents (P8). No row = not consented.
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

        return [
            /** @var array{consented: bool, consented_at: string|null, withdrawn_at: string|null, source: string|null} */
            'birthday' => [
                'consented' => $row !== null && $row->consented,
                'consented_at' => $row?->consented_at?->toIso8601String(),
                'withdrawn_at' => $row?->withdrawn_at?->toIso8601String(),
                'source' => $row?->source,
            ],
        ];
    }
}
