<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * `<title>` composition from config/seo.php — the separator and site name the storefront's
 * layout template also reads (`GET /storefront/seo/settings`), so server defaults and
 * storefront titles always end the same way.
 */
final class SiteTitle
{
    public const string DEFAULT_SEPARATOR = ' | ';

    public static function siteName(): string
    {
        $name = config('seo.site_name');

        return is_string($name) && trim($name) !== '' ? $name : ProductSeo::SITE_NAME;
    }

    public static function separator(): string
    {
        $separator = config('seo.title_separator');

        return is_string($separator) && trim($separator) !== '' ? $separator : self::DEFAULT_SEPARATOR;
    }

    /** "LEGO Classic | Toy Mart BD". */
    public static function of(string $name): string
    {
        return $name.self::separator().self::siteName();
    }
}
