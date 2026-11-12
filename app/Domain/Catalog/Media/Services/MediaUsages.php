<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single resolver for "where is this media used?" (A25 rule 2). Every caller —
 * the list's `used_in_count`, the detail's `used_in`, the Unused filter and count,
 * search by product name, and the delete guard — goes through here, and here asks
 * every tagged MediaUsageProvider.
 */
final readonly class MediaUsages
{
    /** @param iterable<MediaUsageProvider> $providers */
    public function __construct(private iterable $providers) {}

    /** @return list<MediaUsage> */
    public function for(int $mediaId): array
    {
        return $this->forMany([$mediaId])[$mediaId] ?? [];
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, list<MediaUsage>>
     */
    public function forMany(array $mediaIds): array
    {
        $out = [];

        if ($mediaIds === []) {
            return $out;
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->usagesFor($mediaIds) as $id => $usages) {
                $out[$id] = [...($out[$id] ?? []), ...$usages];
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, int> media id → usage count (0 included)
     */
    public function counts(array $mediaIds): array
    {
        $usages = $this->forMany($mediaIds);
        $out = [];

        foreach ($mediaIds as $id) {
            $out[$id] = count($usages[$id] ?? []);
        }

        return $out;
    }

    public function isUsed(int $mediaId): bool
    {
        return $this->for($mediaId) !== [];
    }

    /**
     * Restrict a `media` query to rows used (true) or unused (false) anywhere.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function whereUsed(Builder $query, bool $used): Builder
    {
        $providers = is_array($this->providers) ? $this->providers : iterator_to_array($this->providers, false);

        if ($providers === []) {
            return $used ? $query->whereRaw('1 = 0') : $query;
        }

        $method = $used ? 'where' : 'whereNot';

        return $query->{$method}(function (Builder $q) use ($providers): void {
            foreach ($providers as $provider) {
                $q->orWhereExists($provider->referencing());
            }
        });
    }

    /**
     * OR-clauses for search: media whose usage subject matches the LIKE pattern.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  the inside of an OR group
     */
    public function orWhereSubjectMatches(Builder $query, string $like): void
    {
        foreach ($this->providers as $provider) {
            $sub = $provider->subjectMatching($like);

            if ($sub !== null) {
                $query->orWhereExists($sub);
            }
        }
    }
}
