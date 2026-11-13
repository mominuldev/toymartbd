<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * Redirect paths are locale-independent (docs/api/seo.md §2): `/bn/x/` and `/x` are the
 * same row. Query strings and fragments are not part of a redirect source.
 */
final class PathNormalizer
{
    public const int MAX = 512;

    /** A normalised internal path, or null when it is not a valid one. */
    public static function path(string $raw): ?string
    {
        $path = trim($raw);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        if (preg_match('/[\s?#\\\\]/', $path) === 1 || str_contains($path, '..')) {
            return null;
        }

        if ($path === '/bn') {
            $path = '/';
        } elseif (str_starts_with($path, '/bn/')) {
            $path = substr($path, 3);
        } elseif ($path === '/en') {
            $path = '/';
        } elseif (str_starts_with($path, '/en/')) {
            $path = substr($path, 3);
        }

        $path = $path === '/' ? '/' : rtrim($path, '/');

        return mb_strlen($path) <= self::MAX ? $path : null;
    }

    /** A redirect target: a normalised internal path or an absolute https URL. */
    public static function target(string $raw): ?string
    {
        $value = trim($raw);

        if (str_starts_with($value, 'https://')) {
            return filter_var($value, FILTER_VALIDATE_URL) !== false && mb_strlen($value) <= 2048 ? $value : null;
        }

        // A target may carry a query string (campaign landing pages); keep it.
        $query = '';
        if (str_contains($value, '?')) {
            [$value, $query] = explode('?', $value, 2);
            $query = '?'.$query;
        }

        $path = self::path($value);

        return $path === null || preg_match('/[\s#]/', $query) === 1 ? null : $path.$query;
    }

    /** The path part of an internal target (no query), or null for an external URL. */
    public static function targetPath(string $target): ?string
    {
        if (! str_starts_with($target, '/')) {
            return null;
        }

        return explode('?', $target, 2)[0];
    }
}
