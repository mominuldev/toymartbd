<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Models\SeoMeta;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * XML sitemaps (S-04, P-06c; docs/api/seo.md §5). Writes, on the private `local` disk:
 *
 *   sitemaps/sitemap.xml                       the index
 *   sitemaps/sitemap-{partition}-{en|bn}-{n}.xml
 *
 * Locale-partitioned (one file set per locale, each `<url>` carrying `xhtml:link`
 * alternates for en, bn and x-default) and chunked at 50,000 URLs. Only live, indexable,
 * non-excluded pages without a canonical override are listed. Files are built in a
 * scratch directory and moved into place, so a crawler never reads a half-written set.
 *
 * @phpstan-type FileInfo array{file: string, partition: string, locale: string, part: int, urls: int}
 * @phpstan-type Manifest array{generated_at: string, total_urls: int, files: list<FileInfo>, duration_ms: int}
 */
final class SitemapBuilder
{
    public const string DIR = 'sitemaps';

    public const string MANIFEST = 'seo.sitemap_manifest';

    public const string FILE_PATTERN = '/^sitemap(-[a-z]+-(en|bn)-\d+)?\.xml$/';

    public const array LOCALES = ['en', 'bn'];

    /** Google's limit per sitemap file. */
    public int $chunk = 50000;

    public function __construct(private readonly SeoSubjects $subjects, private readonly SeoDocuments $documents) {}

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** @return Manifest|null */
    public static function manifest(): ?array
    {
        $value = Setting::get(self::MANIFEST);

        /** @var Manifest|null */
        return is_array($value) ? $value : null;
    }

    /** @return Manifest */
    public function build(): array
    {
        $started = microtime(true);
        $disk = self::disk();
        $scratch = self::DIR.'/.build-'.Str::lower(Str::random(12));
        $disk->makeDirectory($scratch);

        /** @var list<FileInfo> $files */
        $files = [];
        /** @var array<string, CarbonImmutable|null> $latest */
        $latest = [];

        // Partition by the path's first segment: products, categories, brands, pages, blog, guides…
        /** @var array<string, list<array{0: string, 1: CarbonImmutable|null}>> $partitions */
        $partitions = [];
        foreach ($this->subjects->all() as $type => $provider) {
            $metas = SeoMeta::query()->where('seoable_type', $type)->get()->keyBy('seoable_id');
            foreach ($provider->live() as $subject) {
                /** @var SeoMeta|null $meta */
                $meta = $metas->get($subject->id);
                if (! $this->documents->inSitemap($subject, $meta)) {
                    continue;
                }
                $partition = self::partition($subject->path);
                $partitions[$partition][] = [$subject->path, $subject->updatedAt];
                $current = $latest[$partition] ?? null;
                if ($subject->updatedAt !== null && ($current === null || $subject->updatedAt->greaterThan($current))) {
                    $latest[$partition] = $subject->updatedAt;
                }
            }
        }
        ksort($partitions);
        foreach ($partitions as $partition => $entries) {
            array_push($files, ...$this->writePartition($disk, $scratch, $partition, $entries));
        }

        $all = collect($latest)->filter()->sort()->last();
        $static = [['/', $all], ['/toys', $latest['products'] ?? $all], ['/categories', $latest['categories'] ?? $all], ['/brands', $latest['brands'] ?? $all]];
        foreach (['blog', 'guides'] as $listing) {
            if (isset($partitions[$listing])) {
                $static[] = ['/'.$listing, $latest[$listing] ?? null];
            }
        }
        $files = [...$this->writePartition($disk, $scratch, 'static', $static), ...$files];

        $now = CarbonImmutable::now();
        $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($files as $file) {
            $index .= '<sitemap><loc>'.self::xml(SiteUrl::base().'/sitemaps/'.$file['file']).'</loc><lastmod>'.$now->toAtomString().'</lastmod></sitemap>'."\n";
        }
        $index .= '</sitemapindex>'."\n";
        $disk->put($scratch.'/sitemap.xml', $index);

        // Swap into place: new files first, the index last, then drop files no longer listed.
        $keep = ['sitemap.xml'];
        foreach ($files as $file) {
            $disk->delete(self::DIR.'/'.$file['file']);
            $disk->move($scratch.'/'.$file['file'], self::DIR.'/'.$file['file']);
            $keep[] = $file['file'];
        }
        $disk->delete(self::DIR.'/sitemap.xml');
        $disk->move($scratch.'/sitemap.xml', self::DIR.'/sitemap.xml');
        foreach ($disk->files(self::DIR) as $path) {
            if (! in_array(basename($path), $keep, true)) {
                $disk->delete($path);
            }
        }
        $disk->deleteDirectory($scratch);

        $manifest = [
            'generated_at' => $now->toAtomString(),
            'total_urls' => array_sum(array_column($files, 'urls')),
            'files' => $files,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        Setting::put(self::MANIFEST, $manifest, SettingGroup::Seo);

        return $manifest;
    }

    /** One file's XML, or null when the name is not a sitemap or it does not exist. */
    public static function read(string $file): ?string
    {
        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            return null;
        }

        $disk = self::disk();
        $path = self::DIR.'/'.$file;

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /** `/products/x` → `products`; anything odd → `pages`. */
    public static function partition(string $path): string
    {
        $first = explode('/', trim($path, '/'))[0];

        return preg_match('/^[a-z]{1,30}$/', $first) === 1 && $first !== 'static' ? $first : 'pages';
    }

    /**
     * @param  iterable<array{0: string, 1: CarbonImmutable|null}>  $entries
     * @return list<FileInfo>
     */
    private function writePartition(Filesystem $disk, string $scratch, string $partition, iterable $entries): array
    {
        /** @var array<string, array{handle: resource, part: int, urls: int, path: string}> $open */
        $open = [];
        $files = [];
        $root = $disk->path($scratch);

        $close = static function (array $state): void {
            fwrite($state['handle'], "</urlset>\n");
            fclose($state['handle']);
        };

        $parts = array_fill_keys(self::LOCALES, 0);

        foreach ($entries as [$path, $lastmod]) {
            foreach (self::LOCALES as $locale) {
                if (! isset($open[$locale]) || $open[$locale]['urls'] >= $this->chunk) {
                    if (isset($open[$locale])) {
                        $close($open[$locale]);
                        $files[] = $this->info($partition, $locale, $open[$locale]['part'], $open[$locale]['urls']);
                    }
                    $parts[$locale]++;
                    $name = "sitemap-{$partition}-{$locale}-{$parts[$locale]}.xml";
                    $handle = fopen($root.'/'.$name, 'wb');
                    if ($handle === false) {
                        throw new RuntimeException('Cannot write '.$name);
                    }
                    fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n");
                    $open[$locale] = ['handle' => $handle, 'part' => $parts[$locale], 'urls' => 0, 'path' => $name];
                }

                fwrite($open[$locale]['handle'], self::url($path, $locale, $lastmod));
                $open[$locale]['urls']++;
            }
        }

        foreach ($open as $locale => $state) {
            $close($state);
            $files[] = $this->info($partition, $locale, $state['part'], $state['urls']);
        }

        usort($files, static fn (array $a, array $b): int => [$a['locale'] === 'en' ? 0 : 1, $a['part']] <=> [$b['locale'] === 'en' ? 0 : 1, $b['part']]);

        return $files;
    }

    /** @return FileInfo */
    private function info(string $partition, string $locale, int $part, int $urls): array
    {
        return ['file' => "sitemap-{$partition}-{$locale}-{$part}.xml", 'partition' => $partition, 'locale' => $locale, 'part' => $part, 'urls' => $urls];
    }

    private static function url(string $path, string $locale, ?CarbonImmutable $lastmod): string
    {
        $en = SiteUrl::localized($path, 'en');
        $bn = SiteUrl::localized($path, 'bn');

        return '<url><loc>'.self::xml($locale === 'bn' ? $bn : $en).'</loc>'
            .($lastmod !== null ? '<lastmod>'.$lastmod->toAtomString().'</lastmod>' : '')
            .'<xhtml:link rel="alternate" hreflang="en" href="'.self::xml($en).'"/>'
            .'<xhtml:link rel="alternate" hreflang="bn" href="'.self::xml($bn).'"/>'
            .'<xhtml:link rel="alternate" hreflang="x-default" href="'.self::xml($en).'"/>'
            ."</url>\n";
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
