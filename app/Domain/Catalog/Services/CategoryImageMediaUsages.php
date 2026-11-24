<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Category images as media usages (`categories.image_media_id`): the library refuses to delete one in use. */
final class CategoryImageMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::table('categories')->select(DB::raw(1))->whereColumn('categories.image_media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::table('categories')
            ->select(DB::raw(1))
            ->whereColumn('categories.image_media_id', 'media.id')
            ->where(static function (Builder $q) use ($like): void {
                $q->where('categories.name', 'like', $like)->orWhere('categories.name_bn', 'like', $like);
            });
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('categories')
            ->whereIn('image_media_id', $mediaIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'image_media_id', 'name', 'name_bn']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->image_media_id][] = new MediaUsage(
                type: 'category_image',
                subjectType: 'category',
                subjectId: (int) $row->id,
                title: (string) $row->name,
                titleBn: is_string($row->name_bn) && $row->name_bn !== '' ? $row->name_bn : null,
                adminPath: '/admin/products/categories/'.(int) $row->id,
            );
        }

        return $out;
    }
}
