<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Actions;

use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Services\BlogCacheTags;
use App\Domain\Seo\Models\SeoMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a draft. A scheduled or published post is refused (422): unpublish it to draft
 * first, so a public URL is never removed by accident. Tags, sections and picks cascade;
 * its seo_meta goes with it.
 */
final class DeleteBlogPost
{
    public function handle(BlogPost $post): void
    {
        if ($post->status !== PostStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('blog.delete_only_drafts')]);
        }

        DB::transaction(function () use ($post): void {
            SeoMeta::query()->where('seoable_type', BlogPost::SEO_TYPE)->where('seoable_id', $post->id)->delete();
            $purge = [$post->id => $post->slug];
            $post->delete();
            BlogCacheTags::purge($purge);
        });
    }
}
