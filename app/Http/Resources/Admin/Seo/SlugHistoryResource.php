<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Seo;

use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\SeoSubjects;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An automatic redirect from a slug change (read-only in the Redirect manager).
 *
 * @property SlugHistory $resource
 */
#[SchemaName('SlugRedirect')]
final class SlugHistoryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $subject = app(SeoSubjects::class)->for($row->sluggable_type)?->find($row->sluggable_id);
        $prefix = $subject !== null && str_ends_with($subject->path, '/'.$subject->slug)
            ? substr($subject->path, 0, -strlen($subject->slug))
            : null;

        return [
            'id' => $row->id,
            'type' => $row->sluggable_type,
            'entity_id' => $row->sluggable_id,
            /** @var string|null */
            'entity_name' => $subject?->name,
            'old_slug' => $row->old_slug,
            'new_slug' => $row->new_slug,
            /** @var string|null */
            'from_path' => $prefix === null ? null : $prefix.$row->old_slug,
            /** @var string|null */
            'to_path' => $subject?->path,
            /** @var 301 */
            'status_code' => 301,
            /** @var string|null */
            'created_at' => $row->created_at?->toAtomString(),
        ];
    }
}
