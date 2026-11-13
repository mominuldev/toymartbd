<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Events\MenusChanged;
use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Services\StoreContentTags;

/**
 * Static pages, menus, banners and testimonials → purge their storefront tags. A page change
 * also purges the menus: menu links to a page resolve its current slug and hide a page that
 * is no longer live.
 */
final class RevalidateStoreContent
{
    public function handle(CmsPageChanged|MenusChanged|BannersChanged|TestimonialsChanged $event): void
    {
        $tags = match (true) {
            $event instanceof CmsPageChanged => array_values(array_filter([
                StoreContentTags::PAGES,
                StoreContentTags::page($event->slug),
                $event->previousSlug !== null ? StoreContentTags::page($event->previousSlug) : null,
                StoreContentTags::MENUS,
            ])),
            $event instanceof MenusChanged => [StoreContentTags::MENUS],
            $event instanceof BannersChanged => [StoreContentTags::BANNERS],
            $event instanceof TestimonialsChanged => [StoreContentTags::TESTIMONIALS],
        };

        RevalidateStorefrontTags::dispatch($tags);
    }
}
