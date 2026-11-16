<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Finance\Models\CapitalHolder;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An owner or partner who puts money in or takes it out (P10). `user` is the linked staff
 * account (id and name only), if any. `can.update` says whether this viewer may change it.
 *
 * @property CapitalHolder $resource
 */
#[SchemaName('CapitalHolder')]
final class CapitalHolderResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $h = $this->resource;
        $user = $h->relationLoaded('user') ? $h->user : null;

        return [
            'id' => $h->id,
            'name' => $h->name,
            'name_bn' => $h->name_bn,
            /** @var 'owner'|'partner' */
            'role' => $h->role->value,
            /** @var array{id: int, name: string}|null */
            'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name],
            'is_active' => $h->is_active,
            /** @var int|null */
            'entries_count' => $h->getAttribute('entries_count') === null ? null : (int) $h->getAttribute('entries_count'),
            /** @var array{update: bool} */
            'can' => ['update' => $request->user()?->can('update', $h) ?? false],
        ];
    }
}
