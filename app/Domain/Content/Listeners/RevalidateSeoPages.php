<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Seo\Events\SeoPagesStale;

/**
 * SeoPagesStale → purge the storefront tags an SEO write made stale: an SEO document
 * (`seo`, `{type}:{slug}`), the redirect map, the sitemaps or robots.txt
 * (docs/api/seo.md §6).
 */
final class RevalidateSeoPages
{
    public function handle(SeoPagesStale $event): void
    {
        $tags = array_values(array_unique(array_filter(
            $event->tags,
            static fn (string $tag): bool => preg_match(RevalidateStorefrontTags::TAG_PATTERN, $tag) === 1,
        )));

        if ($tags !== []) {
            RevalidateStorefrontTags::dispatch(array_slice($tags, 0, RevalidateStorefrontTags::MAX_TAGS));
        }
    }
}
