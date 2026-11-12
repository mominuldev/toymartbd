<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Contracts;

use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;

/**
 * One kind of place a media row can be referenced from (product images today; blog
 * covers, pages and banners later). A provider is tagged MediaUsageProvider::TAG in the
 * container; MediaUsages asks every tagged provider, so adding a usage type never
 * touches the resolver's callers.
 */
interface MediaUsageProvider
{
    public const string TAG = 'media.usage-providers';

    /**
     * A correlated sub-query, for `whereExists`: rows that reference the outer
     * `media.id`. Used for the Unused view, its count and the delete guard.
     */
    public function referencing(): Builder;

    /**
     * A correlated sub-query, for `whereExists`: rows that reference the outer
     * `media.id` and whose subject (e.g. the product's English or Bangla name) matches
     * the LIKE pattern. Null when the type has nothing searchable.
     */
    public function subjectMatching(string $like): ?Builder;

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, list<MediaUsage>> media id → its usages, in display order
     */
    public function usagesFor(array $mediaIds): array;
}
