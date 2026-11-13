<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Seo;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The last sitemap build (SEO → Sitemap & robots).
 *
 * @property array{sitemap_url: string, manifest: array{generated_at: string, total_urls: int, files: list<array{file: string, partition: string, locale: string, part: int, urls: int}>, duration_ms: int}|null} $resource
 */
#[SchemaName('SitemapStatus')]
final class SitemapStatusResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $manifest = $this->resource['manifest'];

        return [
            'sitemap_url' => $this->resource['sitemap_url'],
            /** @var string|null */
            'generated_at' => $manifest['generated_at'] ?? null,
            /** @var int */
            'total_urls' => $manifest['total_urls'] ?? 0,
            /** @var int|null */
            'duration_ms' => $manifest['duration_ms'] ?? null,
            /** @var list<array{file: string, partition: string, locale: 'en'|'bn', part: int, urls: int, url: string}> */
            'files' => array_map(fn (array $f): array => [...$f, 'url' => rtrim(dirname($this->resource['sitemap_url']), '/').'/sitemaps/'.$f['file']], $manifest['files'] ?? []),
        ];
    }
}
