<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Actions;

use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Blog\Services\BlogCacheTags;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a blog category, tag or author. A category or author that any post (draft or not)
 * still uses is refused with a 422 — reassign the posts first. A tag is detached from its
 * posts, which are purged.
 */
final class DeleteBlogTerm
{
    public function handle(BlogCategory|BlogTag|BlogAuthor $term): void
    {
        DB::transaction(function () use ($term): void {
            if ($term instanceof BlogCategory || $term instanceof BlogAuthor) {
                $column = $term instanceof BlogCategory ? 'category_id' : 'author_id';
                if (BlogPost::query()->where($column, $term->id)->exists()) {
                    throw ValidationException::withMessages(['term' => __('blog.term_in_use')]);
                }
                $term->delete();
                BlogCacheTags::purge();

                return;
            }

            $posts = DB::table('blog_post_tag')->join('blog_posts', 'blog_posts.id', '=', 'blog_post_tag.post_id')
                ->where('blog_post_tag.tag_id', $term->id)->pluck('blog_posts.slug', 'blog_posts.id')->all();
            $term->delete();
            BlogCacheTags::purge($posts);
        });
    }
}
