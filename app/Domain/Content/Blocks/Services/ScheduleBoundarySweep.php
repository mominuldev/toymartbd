<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled blocks go live (and expire) without anyone publishing, so the storefront's cached
 * homepage must be dropped when a boundary passes. Run every minute: when any `starts_at` or
 * `ends_at` of the LIVE version fell in (last sweep, now], the placement's tag is revalidated.
 * The last sweep instant is kept in the cache; on a cold cache the window is the last
 * `COLD_WINDOW_SECONDS`, so a restart never misses a boundary by more than that.
 */
final class ScheduleBoundarySweep
{
    public const int COLD_WINDOW_SECONDS = 300;

    public function __construct(private readonly HomeLayoutCache $layouts, private readonly LayoutCacheTags $tags) {}

    /** @return list<string> the tags sent (empty when no boundary passed) */
    public function run(CarbonImmutable $now): array
    {
        $sent = [];

        // Every layout that has ever been published: the homepage and each built page.
        $keys = CmsLayoutVersion::query()->toBase()->distinct()->orderBy('placement')->pluck('placement')->map(static fn (mixed $v): string => (string) $v)->all();
        foreach ($keys as $value) {
            $placement = LayoutKey::fromString($value);
            $key = 'cms:schedule-sweep:'.$placement->value;
            $last = Cache::get($key);
            $since = is_int($last) ? CarbonImmutable::createFromTimestampUTC($last) : $now->subSeconds(self::COLD_WINDOW_SECONDS);

            $live = $this->layouts->live($placement);
            $passed = false;
            foreach (BlockSchedule::boundaries($live['blocks'] ?? []) as $boundary) {
                if ($boundary->greaterThan($since) && $boundary->lessThanOrEqualTo($now)) {
                    $passed = true;
                    break;
                }
            }

            $tags = $passed ? $this->tags->for($placement) : [];
            if ($tags !== []) {
                RevalidateStorefrontTags::dispatch($tags);
                array_push($sent, ...$tags);
            }

            Cache::forever($key, $now->getTimestamp());
        }

        return $sent;
    }
}
