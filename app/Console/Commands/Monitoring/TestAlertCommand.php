<?php

declare(strict_types=1);

namespace App\Console\Commands\Monitoring;

use App\Domain\Monitoring\Actions\RecordAlertOutcome;
use App\Domain\Monitoring\Enums\AlertDecision;
use App\Domain\Monitoring\Services\AlertNotifier;
use App\Domain\Monitoring\Services\AlertRegistry;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `php artisan toymart:alerts:test <name>` — fires one alert on a TEST condition through the
 * real owner channel (launch checklist: "business alerts firing on a test condition"). The
 * notice is tagged [TEST]; no data is faked and the alert's stored state is untouched, so a
 * test never suppresses or resolves a real alert. `all` sends every alert; `--list` names them.
 */
#[Signature('toymart:alerts:test {name? : An alert name, or "all"} {--list : List the alert names}')]
#[Description('Send a [TEST] business alert through the owner channel')]
final class TestAlertCommand extends Command
{
    public function handle(AlertRegistry $registry, AlertNotifier $notifier, RecordAlertOutcome $record): int
    {
        $checks = $registry->all();
        $name = $this->argument('name');

        if ($this->option('list') || ! is_string($name) || $name === '') {
            $this->table(['Alert', 'Severity'], array_map(static fn ($c): array => [$c->name(), $c->severity()->value], array_values($checks)));

            return $this->option('list') ? self::SUCCESS : self::INVALID;
        }

        $selected = $name === 'all' ? $checks : array_filter($checks, static fn ($c): bool => $c->name() === $name);
        if ($selected === []) {
            $this->components->error("Unknown alert [{$name}]. Known: ".implode(', ', array_keys($checks)).'.');

            return self::INVALID;
        }

        $settings = new MonitoringSettings;
        $now = CarbonImmutable::now();
        $failed = false;
        foreach ($selected as $check) {
            $delivery = $notifier->send($check, $check->sample($settings), AlertDecision::Fire, $settings, $now, isTest: true);
            $record->markTested($check, $now);
            $sent = $delivery['mail'] + $delivery['sms'];
            $line = sprintf('%s: %d e-mail, %d SMS recipient(s)%s', $check->name(), $delivery['mail'], $delivery['sms'], $delivery['errors'] === [] ? '' : ' — '.implode('; ', $delivery['errors']));
            if ($sent === 0 || $delivery['errors'] !== []) {
                $failed = true;
                $this->components->error($line.($sent === 0 ? ' — NOTHING SENT: configure MONITORING_ALERT_EMAILS or a super admin with an e-mail.' : ''));
            } else {
                $this->components->info($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
