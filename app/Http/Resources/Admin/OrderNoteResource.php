<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Models\OrderNote;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An internal note on an order (P4-ORD-05). `user_name` is the author's display name, or
 * null for a note written by the system.
 *
 * @property OrderNote $resource
 */
#[SchemaName('OrderNote')]
final class OrderNoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $note = $this->resource;
        $name = $note->getAttribute('user_name');
        if (! is_string($name) && $note->user_id !== null) {
            $name = User::query()->whereKey($note->user_id)->value('name');
        }

        return [
            'id' => $note->id,
            'body' => $note->body,
            'is_customer_visible' => $note->is_customer_visible,
            /** @var int|null */
            'user_id' => $note->user_id,
            /** @var string|null */
            'user_name' => is_string($name) ? $name : null,
            /** @var string|null */
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
