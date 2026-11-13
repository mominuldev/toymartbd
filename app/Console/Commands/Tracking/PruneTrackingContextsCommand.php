<?php

declare(strict_types=1);

namespace App\Console\Commands\Tracking;

use App\Domain\Tracking\Actions\PruneOrderTrackingContexts;
use Illuminate\Console\Command;

/** Retention (docs/api/tracking.md §3.3): blank checkout IP / UA / click ids older than N days. Scheduled daily. */
final class PruneTrackingContextsCommand extends Command
{
    protected $signature = 'toymart:tracking:prune-contexts {--days= : Override tracking.context_retention_days}';

    protected $description = 'Redact the personal data (IP, user agent, click ids) of old order tracking contexts';

    public function handle(PruneOrderTrackingContexts $prune): int
    {
        $days = $this->option('days');
        $count = $prune->handle(is_numeric($days) ? (int) $days : null);
        $this->info("Redacted {$count} order tracking context(s).");

        return self::SUCCESS;
    }
}
