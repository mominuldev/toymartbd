<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Marketing\Events\PromotionChanged;

/**
 * PromotionChanged → purge the product grids (cards carry the flash price and badge),
 * the flash-sale page, and each flash-sale product's page. Sent on every admin save and
 * at each start/end boundary. Tag vocabulary: docs/api/revalidation.md.
 */
final class RevalidatePromotionPages
{
    public function handle(PromotionChanged $event): void
    {
        $tags = ['products', 'flash-sale'];
        foreach (array_slice($event->productSlugs, 0, 45) as $slug) {
            $tags[] = 'product:'.$slug;
        }

        RevalidateStorefrontTags::dispatch(array_values(array_unique($tags)));
    }
}
