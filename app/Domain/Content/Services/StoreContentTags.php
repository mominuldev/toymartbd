<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

/**
 * Storefront cache tags for static pages, menus, banners and testimonials
 * (docs/api/revalidation.md). Page slugs are ≤ 94 characters, so `content:page:{slug}`
 * fits the 100-character tag limit.
 */
final class StoreContentTags
{
    public const string PAGES = 'content:pages';

    public const string MENUS = 'content:menus';

    public const string BANNERS = 'content:banners';

    public const string TESTIMONIALS = 'content:testimonials';

    public static function page(string $slug): string
    {
        return 'content:page:'.$slug;
    }
}
