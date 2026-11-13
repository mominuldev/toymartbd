<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\SlugHistory;
use App\Domain\Seo\Services\RedirectMap;
use Illuminate\Support\Facades\DB;

/**
 * One slug change → `slug_history` (DATABASE-DESIGN §3.9), for any entity type.
 *
 * - A slug that becomes live again stops being a redirect: rows whose `old_slug` is the
 *   new slug are removed, so the live URL always wins.
 * - A row is written only when the old URL was live (a draft's URL never was).
 * - Earlier rows of the same entity are re-pointed to the newest slug, so a redirect is
 *   always one hop (no A → B → C chain).
 *
 * Nests as a savepoint inside the caller's transaction (SaveProductWithSeo).
 */
final class RecordSlugChange
{
    public function handle(string $type, int $id, string $oldSlug, string $newSlug, bool $wasLive, ?int $actorId = null): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        DB::transaction(function () use ($type, $id, $oldSlug, $newSlug, $wasLive, $actorId): void {
            SlugHistory::query()->where('sluggable_type', $type)->where('old_slug', $newSlug)->delete();

            if (! $wasLive) {
                return;
            }

            SlugHistory::query()->create([
                'sluggable_type' => $type,
                'sluggable_id' => $id,
                'old_slug' => $oldSlug,
                'new_slug' => $newSlug,
                'user_id' => $actorId,
            ]);

            SlugHistory::query()
                ->where('sluggable_type', $type)
                ->where('sluggable_id', $id)
                ->update(['new_slug' => $newSlug]);
        });

        DB::afterCommit(static fn () => RedirectMap::forget());
        SeoPagesStale::dispatch(['redirects']);
    }
}
