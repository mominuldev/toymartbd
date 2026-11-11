<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * CategoryChanged → purge the category tree (mega menu) and the category's page,
 * its old URL after a slug change, and every descendant page whose breadcrumbs
 * changed with it. Tag vocabulary: docs/api/revalidation.md.
 */
final class RevalidateCategoryPages
{
    public function handle(CategoryChanged $event): void
    {
        $tags = ['categories', 'category:'.$event->slug];

        if ($event->previousSlug !== null) {
            $tags[] = 'category:'.$event->previousSlug;
        }

        foreach ($event->descendantSlugs as $slug) {
            $tags[] = 'category:'.$slug;
        }

        RevalidateStorefrontTags::dispatch($tags);
    }
}
