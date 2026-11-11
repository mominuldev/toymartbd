<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;

/**
 * ProductChanged → purge product lists and the product's page (and its old URL after
 * a slug change). Tag vocabulary: docs/api/revalidation.md.
 */
final class RevalidateProductPages
{
    public function handle(ProductChanged $event): void
    {
        $tags = ['products', 'product:'.$event->slug];

        if ($event->previousSlug !== null) {
            $tags[] = 'product:'.$event->previousSlug;
        }

        RevalidateStorefrontTags::dispatch($tags);
    }
}
