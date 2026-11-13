<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Banner images as media usages (`banners.media_id`): the library refuses to delete one in use. */
final class BannerMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::table('banners')->select(DB::raw(1))->whereColumn('banners.media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::table('banners')
            ->select(DB::raw(1))
            ->whereColumn('banners.media_id', 'media.id')
            ->where(static function (Builder $q) use ($like): void {
                $q->where('banners.name', 'like', $like)->orWhere('banners.title_bn', 'like', $like);
            });
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('banners')
            ->whereIn('media_id', $mediaIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'media_id', 'name', 'title_bn']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->media_id][] = new MediaUsage(
                type: 'banner',
                subjectType: 'banner',
                subjectId: (int) $row->id,
                title: (string) $row->name,
                titleBn: is_string($row->title_bn) && $row->title_bn !== '' ? $row->title_bn : null,
                adminPath: '/admin/store/banners/'.(int) $row->id,
            );
        }

        return $out;
    }
}
