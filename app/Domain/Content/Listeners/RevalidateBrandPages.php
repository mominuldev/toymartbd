<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * BrandChanged → purge the brand list and the brand's page (and its old URL after
 * a slug change). Tag vocabulary: docs/api/revalidation.md.
 */
final class RevalidateBrandPages
{
    public function handle(BrandChanged $event): void
    {
        $tags = ['brands', 'brand:'.$event->slug];

        if ($event->previousSlug !== null) {
            $tags[] = 'brand:'.$event->previousSlug;
        }

        RevalidateStorefrontTags::dispatch($tags);
    }
}
