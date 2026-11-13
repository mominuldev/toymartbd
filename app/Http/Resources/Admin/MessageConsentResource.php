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
 * `birthday` = reminders with a gift offer before each child's birthday (P8 batch 3).
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
             * `confirmation_method` / `confirmation_note`: how staff confirmed the customer agreed
             * (kept with `consented_at`; null for a storefront opt-in).
             *
             * @var array{consented: bool, consented_at: string|null, withdrawn_at: string|null, source: 'admin'|'storefront'|null, recorded_by: array{id: int, name: string}|null, confirmation_method: 'phone_call'|'in_person'|'written'|'other'|null, confirmation_note: string|null}
             */
            'birthday' => [
                'consented' => $row !== null && $row->consented,
                'consented_at' => $row?->consented_at?->toIso8601String(),
                'withdrawn_at' => $row?->withdrawn_at?->toIso8601String(),
                'source' => $row?->source,
                'recorded_by' => $by === null ? null : ['id' => $by->id, 'name' => $by->name],
                'confirmation_method' => $row?->confirmation_method?->value,
                'confirmation_note' => $row?->confirmation_note,
            ],
        ];
    }
}
