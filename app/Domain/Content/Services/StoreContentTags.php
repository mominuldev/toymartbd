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

    /**
     * Every tag one static page is cached under on the storefront: its content fetch
     * (`content:page:{slug}`) and its SEO document fetch (`page:{slug}`, lib/seo/fetch.ts
     * seoTags — the morph key `page`). A page save and a page SEO save both purge all of them,
     * so neither the body nor the <head> can outlive the write.
     *
     * @return list<string>
     */
    public static function pageAll(string $slug): array
    {
        return [self::page($slug), 'page:'.$slug];
    }
}
