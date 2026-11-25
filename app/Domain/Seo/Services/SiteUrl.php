<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Support\StorefrontUrl;

/**
 * The public storefront origin (STOREFRONT_URL), falling back to the production domain
 * so a preview never shows an empty host. Locale paths: Bangla at the root, English
 * under /en (rule 35, owner decision 2026-11-25) — see App\Support\StorefrontUrl.
 */
final class SiteUrl
{
    public static function base(): string
    {
        $url = config('services.storefront.url');

        return rtrim(is_string($url) && $url !== '' ? $url : ProductSeo::SITE_URL, '/');
    }

    public static function localized(string $path, string $locale): string
    {
        return StorefrontUrl::url(self::base(), $locale, $path);
    }
}
