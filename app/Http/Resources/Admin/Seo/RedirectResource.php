<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Seo;

use App\Domain\Seo\Models\Redirect;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * @property Redirect $resource
 */
#[SchemaName('Redirect')]
final class RedirectResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'from_path' => $r->from_path,
            'to_path' => $r->to_path,
            /** @var 301|302 */
            'status_code' => $r->status_code,
            'is_active' => $r->is_active,
            /** @var 'manual'|'import' */
            'source' => $r->source,
            'hit_count' => $r->hit_count,
            /** @var string|null */
            'last_hit_at' => $r->last_hit_at?->toAtomString(),
            /** @var string|null */
            'note' => $r->note,
            /** @var string|null */
            'created_at' => $r->created_at?->toAtomString(),
            /** @var string|null */
            'updated_at' => $r->updated_at?->toAtomString(),
        ];
    }
}
