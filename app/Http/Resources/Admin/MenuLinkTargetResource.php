<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One result of the menu link picker: `{ id, label, label_bn, path, live }`.
 *
 * @property array{id: int, label: string, label_bn: string|null, path: string, live: bool} $resource
 */
#[SchemaName('MenuLinkTarget')]
final class MenuLinkTargetResource extends ApiResource
{
    /** @return array{id: int, label: string, label_bn: string|null, path: string, live: bool} */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
