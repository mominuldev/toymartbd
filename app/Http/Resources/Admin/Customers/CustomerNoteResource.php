<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\CustomerNote;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A staff note on a customer. Append-only; `user_name` is the author (staff display name),
 * null for a note written by the system.
 *
 * @property CustomerNote $resource
 */
#[SchemaName('CustomerNote')]
final class CustomerNoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $note = $this->resource;
        $name = $note->user_id === null ? null : User::query()->whereKey($note->user_id)->value('name');

        return [
            'id' => $note->id,
            'body' => $note->body,
            'is_pinned' => $note->is_pinned,
            /** @var int|null */
            'user_id' => $note->user_id,
            /** @var string|null */
            'user_name' => is_string($name) ? $name : null,
            /** @var string|null */
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
