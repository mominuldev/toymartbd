<?php

declare(strict_types=1);

namespace App\Console\Commands\Marketing;

use App\Domain\Marketing\Services\PromotionBoundaries;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute: revalidate the storefront for promotions that started or ended since the
 * last run (P7). The last run time is kept in the cache; a missing value looks back 5
 * minutes, so a restart or a skipped minute is covered.
 */
final class AnnouncePromotionBoundaries extends Command
{
    public const string CACHE_KEY = 'marketing:promotion-boundaries:last-run';

    protected $signature = 'toymart:promotions:boundaries';

    protected $description = 'Revalidate storefront pages for promotions that started or ended since the last run';

    public function handle(PromotionBoundaries $boundaries): int
    {
        $now = now();
        $last = Cache::get(self::CACHE_KEY);
        $since = is_string($last) ? Carbon::parse($last) : $now->copy()->subMinutes(5);

        $count = $boundaries->announce($since, $now);
        Cache::forever(self::CACHE_KEY, $now->toIso8601String());
        $this->info("Revalidated {$count} promotion(s).");

        return self::SUCCESS;
    }
}
