<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * Which storefront paths are live pages right now: an entity of a registered type, or a
 * built-in route. A redirect may never shadow one (docs/api/seo.md §2).
 */
final readonly class LivePaths
{
    /** Built-in storefront routes (locale-independent). */
    public const array RESERVED = [
        '/', '/cart', '/checkout', '/search', '/track', '/categories', '/brands', '/toys', '/blog', '/guides',
        '/delivery-charge', '/maintenance', '/robots.txt', '/sitemap.xml', '/pages/about',
    ];

    /** Prefixes owned by the storefront itself. */
    public const array RESERVED_PREFIXES = ['/account', '/orders', '/api', '/_next', '/sitemaps'];

    public function __construct(private SeoSubjects $subjects) {}

    public function reserved(string $path): bool
    {
        if (in_array($path, self::RESERVED, true)) {
            return true;
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entity paths among `$paths` that are live now.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function liveAmong(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $live = [];
        foreach ($this->subjects->all() as $provider) {
            foreach (array_chunk($paths, 1000) as $chunk) {
                array_push($live, ...$provider->livePaths($chunk));
            }
        }

        return array_values(array_unique($live));
    }

    public function isLive(string $path): bool
    {
        return $this->liveAmong([$path]) !== [];
    }
}
