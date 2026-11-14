<?php

declare(strict_types=1);

namespace App\Console\Commands\Monitoring;

use App\Domain\Monitoring\Services\AlertRunner;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The scheduled business-alert run (P10, SYSTEM-ARCHITECTURE §11), every five minutes.
 * Idempotent: the owner hears once when an alert starts, every `renotify_minutes` while it
 * lasts, and once when it resolves. Does nothing while `monitoring.alerts.enabled` is false
 * unless `--force`. Exits 1 when any alert is firing, so the scheduler output shows it.
 */
#[Signature('toymart:alerts:run {--only=* : Run only these alerts} {--force : Run even when alerts are disabled}')]
#[Description('Evaluate the business alerts and notify the owner channel on a change')]
final class RunAlertsCommand extends Command
{
    public function handle(AlertRunner $runner): int
    {
        if (! (bool) (new MonitoringSettings)->get('alerts.enabled', false) && ! $this->option('force')) {
            $this->components->info('Business alerts are disabled (MONITORING_ALERTS_ENABLED). Nothing evaluated.');

            return self::SUCCESS;
        }

        /** @var list<string> $only */
        $only = (array) $this->option('only');
        $outcomes = $runner->run(CarbonImmutable::now(), $only === [] ? null : $only);

        $firing = 0;
        $rows = [];
        foreach ($outcomes as $name => $outcome) {
            $result = $outcome['result'];
            $state = match (true) {
                $outcome['error'] !== null => 'error ('.$outcome['error'].')',
                $result === null || ! $result->applicable => 'n/a',
                $result->firing => 'FIRING',
                default => 'ok',
            };
            $firing += $result !== null && $result->firing ? 1 : 0;
            $rows[] = [$name, $state, $outcome['decision']->value, $result?->summary('en') ?? ''];
        }
        $this->table(['Alert', 'State', 'Notice', 'Summary'], $rows);

        return $firing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
