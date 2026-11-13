<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Homepage CMS block images (hero slides, promo split) as media usages, read from the
 * `cms_block_media` projection: the library shows "Homepage · live / draft" and refuses to
 * delete an image a block still uses (the foreign key restricts the delete as well).
 * Usage types: `cms_block_live`, `cms_block_draft`.
 */
final class CmsBlockMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::table('cms_block_media')->select(DB::raw(1))->whereColumn('cms_block_media.media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::table('cms_block_media')
            ->select(DB::raw(1))
            ->whereColumn('cms_block_media.media_id', 'media.id')
            ->where(static function (Builder $q) use ($like): void {
                $q->where('cms_block_media.block_label', 'like', $like)->orWhere('cms_block_media.block_type', 'like', $like);
            });
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('cms_block_media as cbm')
            ->leftJoin('cms_blocks as b', 'b.uuid', '=', 'cbm.block_uuid')
            ->whereIn('cbm.media_id', $mediaIds)
            ->orderByDesc('cbm.layer') // live before draft
            ->orderBy('cbm.placement')
            ->orderBy('cbm.id')
            ->get(['cbm.media_id', 'cbm.placement', 'cbm.layer', 'cbm.block_type', 'cbm.block_label', 'b.id as block_id', 'b.position']);

        $out = [];
        foreach ($rows as $row) {
            $title = is_string($row->block_label) && $row->block_label !== '' ? $row->block_label : (string) $row->block_type;
            $out[(int) $row->media_id][] = new MediaUsage(
                type: 'cms_block_'.$row->layer,
                subjectType: 'cms_block',
                subjectId: (int) ($row->block_id ?? 0),
                title: $title,
                titleBn: null,
                adminPath: '/admin/store/'.$row->placement,
                position: $row->position === null ? null : (int) $row->position + 1,
            );
        }

        return $out;
    }
}
