<?php

declare(strict_types=1);

namespace App\Console\Commands\Monitoring;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (routes/console.php). It only records that the scheduler fired, so a
 * scheduler that stopped — a missing cron line, a crashed host, a deploy that forgot the
 * crontab — is visible within minutes, not after a lost nightly backup.
 *
 * THE single scheduler heartbeat (P10). Readers:
 *  - `GET /api/v1/health` (check `scheduler`, stale after `monitoring.health.scheduler_stale_minutes`);
 *  - `toymart:launch:check` — read `SystemHeartbeat::lastSuccess(SystemHeartbeat::SCHEDULER)`
 *    and compare with {@see self::MAX_AGE_MINUTES}.
 * Stored in `system_heartbeats` (database), not the cache, so a cache flush cannot hide it.
 */
#[Signature('toymart:scheduler:heartbeat')]
#[Description('Record that the scheduler ran (read by the health endpoint and toymart:launch:check)')]
final class SchedulerHeartbeatCommand extends Command
{
    public const int MAX_AGE_MINUTES = 5;

    public function handle(): int
    {
        SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);

        return self::SUCCESS;
    }
}
