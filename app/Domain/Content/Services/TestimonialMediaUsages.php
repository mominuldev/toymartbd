<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Testimonial images as media usages (`testimonials.media_id`): the library refuses to delete one in use. */
final class TestimonialMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::table('testimonials')->select(DB::raw(1))->whereColumn('testimonials.media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::table('testimonials')
            ->select(DB::raw(1))
            ->whereColumn('testimonials.media_id', 'media.id')
            ->where(static function (Builder $q) use ($like): void {
                $q->where('testimonials.name', 'like', $like)->orWhere('testimonials.name_bn', 'like', $like);
            });
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('testimonials')
            ->whereIn('media_id', $mediaIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'media_id', 'name', 'name_bn']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->media_id][] = new MediaUsage(
                type: 'testimonial',
                subjectType: 'testimonial',
                subjectId: (int) $row->id,
                title: (string) $row->name,
                titleBn: is_string($row->name_bn) && $row->name_bn !== '' ? $row->name_bn : null,
                adminPath: '/admin/store/testimonials/'.(int) $row->id,
            );
        }

        return $out;
    }
}
