<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Models\Setting;

/**
 * robots.txt (S-04): the admin-edited text in setting `seo.robots_txt`, validated
 * directive by directive, always served with the sitemap line.
 */
final class RobotsTxt
{
    public const string SETTING = 'seo.robots_txt';

    public const int MAX_BYTES = 32768;

    private const array DIRECTIVES = ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay'];

    public static function sitemapUrl(): string
    {
        return SiteUrl::base().'/sitemap.xml';
    }

    /** The shipped default: crawl the shop, never the private or parameter-only routes. */
    public static function defaultContent(): string
    {
        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /account',
            'Disallow: /orders',
            'Disallow: /search',
            'Disallow: /bn/cart',
            'Disallow: /bn/checkout',
            'Disallow: /bn/account',
            'Disallow: /bn/orders',
            'Disallow: /bn/search',
            'Disallow: /api/',
            '',
            'Sitemap: '.self::sitemapUrl(),
        ])."\n";
    }

    /** The stored text, or null when the admin never saved one. */
    public static function stored(): ?string
    {
        $value = Setting::get(self::SETTING);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** What the storefront serves: the stored text (or the default), with the sitemap line guaranteed. */
    public static function served(): string
    {
        $content = str_replace("\r\n", "\n", self::stored() ?? self::defaultContent());
        $sitemap = self::sitemapUrl();

        if (preg_match('/^\s*sitemap\s*:\s*'.preg_quote($sitemap, '/').'\s*$/mi', $content) !== 1) {
            $content = rtrim($content)."\n\nSitemap: ".$sitemap;
        }

        return rtrim($content)."\n";
    }

    /**
     * @return list<string> human-readable errors (empty = valid)
     */
    public static function validate(string $content): array
    {
        $errors = [];
        $group = [];
        $groupOpen = false;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        foreach ($lines as $i => $raw) {
            $n = $i + 1;
            $line = trim((string) preg_replace('/#.*$/', '', $raw));
            if ($line === '') {
                continue;
            }
            if (! str_contains($line, ':')) {
                $errors[] = (string) __('seo.robots.malformed', ['line' => $n]);

                continue;
            }
            [$directive, $value] = array_map(trim(...), explode(':', $line, 2));
            $key = strtolower($directive);

            if (! in_array($key, self::DIRECTIVES, true)) {
                $errors[] = (string) __('seo.robots.unknown_directive', ['line' => $n, 'directive' => $directive]);

                continue;
            }

            switch ($key) {
                case 'user-agent':
                    if (! $groupOpen) {
                        $group = [];
                    }
                    $group[] = strtolower($value);
                    $groupOpen = true;
                    break;
                case 'allow':
                case 'disallow':
                    $groupOpen = false;
                    if ($group === []) {
                        $errors[] = (string) __('seo.robots.rule_before_agent', ['line' => $n]);
                    } elseif ($value !== '' && ! str_starts_with($value, '/') && ! str_starts_with($value, '*')) {
                        $errors[] = (string) __('seo.robots.bad_path', ['line' => $n]);
                    } elseif ($key === 'disallow' && $value === '/' && in_array('*', $group, true)) {
                        $errors[] = (string) __('seo.robots.blocks_everything', ['line' => $n]);
                    }
                    break;
                case 'sitemap':
                    if (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#', $value) !== 1) {
                        $errors[] = (string) __('seo.robots.bad_sitemap', ['line' => $n]);
                    }
                    break;
                case 'crawl-delay':
                    $groupOpen = false;
                    if ($group === []) {
                        $errors[] = (string) __('seo.robots.rule_before_agent', ['line' => $n]);
                    } elseif (! is_numeric($value) || (float) $value < 0) {
                        $errors[] = (string) __('seo.robots.bad_delay', ['line' => $n]);
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * Whether the served robots.txt lets a crawler (`User-agent: *` group, or the default when no
     * group names it) fetch this path — Google's matching (RFC 9309): the longest matching rule
     * wins, Allow wins a tie, `*` matches any run of characters and `$` anchors the end. Used to
     * keep the sitemap from submitting a URL robots.txt blocks.
     */
    public static function allows(string $path, ?string $content = null): bool
    {
        $rules = self::rulesForAll($content ?? self::served());
        $best = null;
        foreach ($rules as [$allow, $pattern]) {
            if ($pattern === '' || ! self::matches($pattern, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($best === null || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best === null || $best[0];
    }

    /** @return list<array{bool, string}> [allow?, pattern] of the group(s) for `*` */
    private static function rulesForAll(string $content): array
    {
        $rules = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $raw) {
            $line = trim((string) preg_replace('/#.*$/', '', $raw));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$directive, $value] = array_map(trim(...), explode(':', $line, 2));
            $key = strtolower($directive);
            if ($key === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
            } elseif ($key === 'allow' || $key === 'disallow') {
                $inRules = true;
                if (in_array('*', $agents, true)) {
                    $rules[] = [$key === 'allow', $value];
                }
            }
        }

        return $rules;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = implode('.*', array_map(static fn (string $part): string => preg_quote($part, '#'), explode('*', $anchored ? substr($pattern, 0, -1) : $pattern)));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
