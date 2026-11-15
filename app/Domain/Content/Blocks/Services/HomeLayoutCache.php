<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use Illuminate\Support\Facades\Cache;

/**
 * The live version of a layout (the homepage or a page), cached until the next publish or revert (both call
 * forget()). Only the frozen snapshot is cached: schedule windows, stock and product data are
 * resolved per request, and the storefront's ISR cache (LayoutCacheTags) sits in front.
 */
final class HomeLayoutCache
{
    public const int TTL_SECONDS = 86_400;

    /** @return array{version: int, published_at: string, blocks: list<array<string, mixed>>}|null */
    public function live(LayoutKey $placement): ?array
    {
        /** @var array{version: int, published_at: string, blocks: list<array<string, mixed>>}|false $cached */
        $cached = Cache::remember(self::key($placement), self::TTL_SECONDS, static function () use ($placement): array|false {
            $version = CmsLayoutVersion::latestFor($placement);

            return $version === null ? false : [
                'version' => $version->version,
                'published_at' => $version->published_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'blocks' => $version->blocks,
            ];
        });

        return $cached === false ? null : $cached;
    }

    public function forget(LayoutKey $placement): void
    {
        Cache::forget(self::key($placement));
    }

    private static function key(LayoutKey $placement): string
    {
        return 'cms:layout:'.$placement->value;
    }
}
