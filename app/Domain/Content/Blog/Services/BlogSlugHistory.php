<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

use App\Domain\Seo\Events\SlugChanged;

/**
 * Slug changes of blog URLs (types `blog_post`, `blog_category`, `blog_tag`, `blog_author`)
 * go to the SEO module as {@see SlugChanged} (docs/api/seo.md §1), dispatched after commit.
 * Its listener writes `slug_history` only when the old URL was public, re-points older rows
 * so a redirect is one hop, and drops a row whose old slug is live again.
 */
final class BlogSlugHistory
{
    public function record(string $type, int $id, ?string $previousSlug, string $newSlug, bool $wasPublic, ?int $actorId): void
    {
        if ($previousSlug === null || $previousSlug === $newSlug) {
            return;
        }

        SlugChanged::dispatch($type, $id, $previousSlug, $newSlug, $wasPublic, $actorId);
    }
}
