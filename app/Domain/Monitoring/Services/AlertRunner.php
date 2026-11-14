<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Actions\RecordAlertOutcome;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs every business alert once (`toymart:alerts:run`, every five minutes): evaluate, fold
 * into the stored state, notify outside the transaction when the state says so. One check
 * that throws is logged and reported as such; it never stops the others.
 */
final readonly class AlertRunner
{
    public function __construct(
        private AlertRegistry $registry,
        private RecordAlertOutcome $record,
        private AlertNotifier $notifier,
    ) {}

    /**
     * @param  list<string>|null  $only
     * @return array<string, array{result: AlertResult|null, decision: AlertDecision, error: string|null}>
     */
    public function run(CarbonImmutable $now, ?array $only = null): array
    {
        $settings = new MonitoringSettings;
        $renotify = $settings->int('alerts.renotify_minutes', 240);
        $outcomes = [];

        foreach ($this->registry->all() as $name => $check) {
            if ($only !== null && ! in_array($name, $only, true)) {
                continue;
            }

            try {
                $result = $check->evaluate($settings, $now);
                $decision = $this->record->handle($check, $result, $now, $renotify);
            } catch (Throwable $e) {
                Log::error('monitoring.alert.check_failed', ['alert' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);
                $outcomes[$name] = ['result' => null, 'decision' => AlertDecision::None, 'error' => $e::class];

                continue;
            }

            if ($decision->notifies()) {
                $this->notifier->send($check, $result, $decision, $settings, $now);
            }
            $outcomes[$name] = ['result' => $result, 'decision' => $decision, 'error' => null];
        }

        return $outcomes;
    }
}
