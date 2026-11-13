<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * The public storefront origin (STOREFRONT_URL), falling back to the production domain
 * so a preview never shows an empty host. Locale paths: English at the root, Bangla
 * under /bn (rule 35).
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
        $path = $path === '' ? '/' : $path;

        if ($locale === 'bn') {
            return self::base().($path === '/' ? '/bn' : '/bn'.$path);
        }

        return self::base().($path === '/' ? '' : $path);
    }
}
