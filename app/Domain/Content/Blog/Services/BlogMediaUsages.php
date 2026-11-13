<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Blog cover images and author avatars as media usages, so the library shows where a file
 * is used and refuses to delete it (the foreign keys restrict the delete as well).
 */
final class BlogMediaUsages implements MediaUsageProvider
{
    public function referencing(): Builder
    {
        return DB::query()->selectRaw('1')->fromSub(
            DB::table('blog_posts')->select('cover_media_id as media_id')->whereNotNull('cover_media_id')
                ->unionAll(DB::table('blog_authors')->select('avatar_media_id as media_id')->whereNotNull('avatar_media_id')),
            'blog_media',
        )->whereColumn('blog_media.media_id', 'media.id');
    }

    public function subjectMatching(string $like): Builder
    {
        return DB::query()->selectRaw('1')->fromSub(
            DB::table('blog_posts')->select('cover_media_id as media_id', 'title as name', 'title_bn as name_bn')->whereNotNull('cover_media_id')
                ->unionAll(DB::table('blog_authors')->select('avatar_media_id as media_id', 'name', 'name_bn')->whereNotNull('avatar_media_id')),
            'blog_media',
        )->whereColumn('blog_media.media_id', 'media.id')
            ->where(static fn (Builder $q) => $q->where('blog_media.name', 'like', $like)->orWhere('blog_media.name_bn', 'like', $like));
    }

    public function usagesFor(array $mediaIds): array
    {
        $out = [];

        foreach (DB::table('blog_posts')->whereIn('cover_media_id', $mediaIds)->orderBy('title')->orderBy('id')->get(['id', 'cover_media_id', 'title', 'title_bn']) as $row) {
            $out[(int) $row->cover_media_id][] = new MediaUsage(
                type: 'blog_cover',
                subjectType: 'blog_post',
                subjectId: (int) $row->id,
                title: (string) $row->title,
                titleBn: is_string($row->title_bn) && $row->title_bn !== '' ? $row->title_bn : null,
                adminPath: '/admin/store/blog/'.$row->id,
            );
        }

        foreach (DB::table('blog_authors')->whereIn('avatar_media_id', $mediaIds)->orderBy('name')->orderBy('id')->get(['id', 'avatar_media_id', 'name', 'name_bn']) as $row) {
            $out[(int) $row->avatar_media_id][] = new MediaUsage(
                type: 'blog_author_avatar',
                subjectType: 'blog_author',
                subjectId: (int) $row->id,
                title: (string) $row->name,
                titleBn: is_string($row->name_bn) && $row->name_bn !== '' ? $row->name_bn : null,
                adminPath: '/admin/store/blog/authors/'.$row->id,
            );
        }

        return $out;
    }
}
