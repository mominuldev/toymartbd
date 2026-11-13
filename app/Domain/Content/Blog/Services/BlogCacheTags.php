<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * Storefront cache tags for the blog (docs/api/revalidation.md):
 * - `content:blog` — every blog/guide list, taxonomy page and the home-page teaser;
 * - `content:post:{id}` — one post, by id (for readers that know the id);
 * - `post:{slug}` — one post page, by slug: the storefront fetches a post by its slug, so this is
 *   the tag its detail fetch carries (and the SEO module's `{type}:{slug}` tag for posts). The old
 *   slug is sent too after a slug change. Post slugs are ≤ 94 chars, so the tag fits 100.
 * The job is queued after commit (rule 4).
 */
final class BlogCacheTags
{
    public const string LIST = 'content:blog';

    public static function post(int $id): string
    {
        return 'content:post:'.$id;
    }

    public static function slug(string $slug): string
    {
        return 'post:'.$slug;
    }

    /**
     * @param  array<int, string|list<string>>  $posts  post id => its slug (or slugs: current and previous)
     */
    public static function purge(array $posts = []): void
    {
        $tags = [self::LIST];
        foreach ($posts as $id => $slugs) {
            $tags[] = self::post($id);
            foreach ((array) $slugs as $slug) {
                $tags[] = self::slug($slug);
            }
        }

        RevalidateStorefrontTags::dispatch($tags);
    }
}
