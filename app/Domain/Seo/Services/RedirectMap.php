<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Models\Redirect;
use App\Domain\Seo\Models\SlugHistory;
use Illuminate\Support\Facades\Cache;

/**
 * The storefront's whole redirect table, resolved once on the server (docs/api/seo.md §3):
 * active manual redirects plus every `slug_history` row as a path, with
 *
 * - live URLs pruned (a live page always wins over a redirect),
 * - manual rows winning over automatic ones for the same source,
 * - chains collapsed (A → B → C is served A → C), and
 * - loops dropped.
 *
 * Cached; every write that changes it calls {@see self::forget()}.
 *
 * @phpstan-type Entry array{id: int|null, from: string, to: string, status: int}
 */
final readonly class RedirectMap
{
    public const string CACHE_KEY = 'seo:redirect-map';

    public const int MAX_HOPS = 10;

    public function __construct(private LivePaths $live, private SeoSubjects $subjects) {}

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        /** @var list<Entry> */
        return Cache::remember(self::CACHE_KEY, 300, fn (): array => $this->build());
    }

    /** @return list<Entry> */
    public function build(): array
    {
        /** @var array<string, Entry> $map */
        $map = [];

        $current = [];
        foreach (SlugHistory::query()->orderBy('id')->cursor() as $row) {
            $key = $row->sluggable_type.':'.$row->sluggable_id;
            if (! array_key_exists($key, $current)) {
                $current[$key] = $this->subjects->for($row->sluggable_type)?->find($row->sluggable_id);
            }
            $subject = $current[$key];
            if ($subject === null || ! str_ends_with($subject->path, '/'.$subject->slug)) {
                continue;
            }
            $from = substr($subject->path, 0, -strlen($subject->slug)).$row->old_slug;
            if ($from !== $subject->path) {
                $map[$from] = ['id' => null, 'from' => $from, 'to' => $subject->path, 'status' => 301];
            }
        }

        foreach (Redirect::query()->where('is_active', true)->orderBy('id')->cursor() as $row) {
            $map[$row->from_path] = ['id' => $row->id, 'from' => $row->from_path, 'to' => $row->to_path, 'status' => $row->status_code];
        }

        foreach ($this->live->liveAmong(array_keys($map)) as $path) {
            unset($map[$path]);
        }

        $resolved = [];
        foreach ($map as $from => $entry) {
            $final = self::follow($map, $entry['to'], $from);
            if ($final === null || PathNormalizer::targetPath($final) === $from) {
                continue; // a loop: never serve it
            }
            $resolved[] = ['id' => $entry['id'], 'from' => $from, 'to' => $final, 'status' => $entry['status']];
        }

        usort($resolved, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));

        return $resolved;
    }

    /**
     * Follow `$target` through `$map` to its final destination; null on a loop back to
     * `$origin` or past MAX_HOPS.
     *
     * @param  array<string, array{to: string}>  $map
     */
    public static function follow(array $map, string $target, string $origin): ?string
    {
        $seen = [$origin => true];

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $path = PathNormalizer::targetPath($target);
            if ($path === null || ! isset($map[$path])) {
                return $target;
            }
            if (isset($seen[$path])) {
                return null;
            }
            $seen[$path] = true;
            $target = $map[$path]['to'];
        }

        return null;
    }
}
