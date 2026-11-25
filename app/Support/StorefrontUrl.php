<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Public storefront paths per locale (CLAUDE.md rule 35). **Bangla first** (owner decision
 * 2026-11-25): Bangla is served at the root (`/products/x`), English under `/en`
 * (`/en/products/x`). Slugs are shared, so a path differs between the two only by the prefix.
 * The storefront 308-redirects the old `/bn/...` URLs to the unprefixed one, and its
 * x-default hreflang is the Bangla (unprefixed) URL.
 *
 * Every link the API builds into a message, document, sitemap or admin preview goes through
 * here, so the scheme lives in one place. Any locale other than `en` is the default (Bangla).
 *
 *   path('bn', '/track')  /track          path('en', '/track')  /en/track
 *   path('bn', '/')       /               path('en', '/')       /en
 *   url('https://shop.test/', 'bn', '/')  https://shop.test
 *   url('https://shop.test', 'en', '/cart/restore/abc')  https://shop.test/en/cart/restore/abc
 */
final class StorefrontUrl
{
    /** The locale served at the root, without a prefix. */
    public const string DEFAULT_LOCALE = 'bn';

    /** The prefix for a locale: '' for Bangla (the root), `/en` for English. */
    public static function prefix(string $locale): string
    {
        return $locale === 'en' ? '/en' : '';
    }

    /**
     * A locale-free path (`/products/x`, `/track?order=1`) as that locale's storefront path.
     * The root is `/` in Bangla and `/en` (no trailing slash) in English.
     */
    public static function path(string $locale, string $path): string
    {
        $path = $path === '' ? '/' : $path;
        $prefix = self::prefix($locale);

        if ($prefix === '') {
            return $path;
        }

        // `/` → `/en`, `/?x=1` → `/en?x=1`, `/track` → `/en/track`.
        return str_starts_with($path, '/?') || $path === '/' ? $prefix.substr($path, 1) : $prefix.$path;
    }

    /**
     * An absolute URL on `$base` (an origin, trailing slash optional). The Bangla homepage is
     * the bare origin, matching how the English one used to be written.
     */
    public static function url(string $base, string $locale, string $path): string
    {
        $local = self::path($locale, $path);

        return rtrim($base, '/').($local === '/' ? '' : $local);
    }
}
