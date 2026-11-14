<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertDecision;
use App\Domain\Monitoring\Enums\AlertState;
use App\Domain\Monitoring\Models\MonitoringAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Folds one evaluation into the alert's stored state and decides whether the owner hears about
 * it — the de-duplication (P10):
 *
 *   ok      + firing  → Fire    (state firing, fired_at, notified)
 *   firing  + firing  → Remind  only when `renotify_minutes` have passed since the last notice
 *   firing  + ok      → Resolve (state ok, resolved_at, notified once)
 *   anything else     → None    (a re-run in the same state is a no-op)
 *
 * A not-applicable result leaves the state alone. The row is locked FOR UPDATE, so two
 * overlapping runs cannot both send. The notice itself goes out AFTER this commits (rule 4).
 */
final readonly class RecordAlertOutcome
{
    public function handle(AlertCheck $check, AlertResult $result, CarbonImmutable $now, int $renotifyMinutes): AlertDecision
    {
        return DB::transaction(function () use ($check, $result, $now, $renotifyMinutes): AlertDecision {
            MonitoringAlert::query()->insertOrIgnore(['name' => $check->name(), 'state' => AlertState::Ok->value, 'notify_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
            /** @var MonitoringAlert $alert */
            $alert = MonitoringAlert::query()->whereKey($check->name())->lockForUpdate()->firstOrFail();
            $at = Carbon::instance($now);
            $alert->last_checked_at = $at;

            $decision = AlertDecision::None;
            if ($result->applicable) {
                $decision = match (true) {
                    $result->firing && $alert->state === AlertState::Ok => AlertDecision::Fire,
                    $result->firing && ($alert->last_notified_at === null || $alert->last_notified_at->lessThanOrEqualTo($now->subMinutes(max(1, $renotifyMinutes)))) => AlertDecision::Remind,
                    ! $result->firing && $alert->state === AlertState::Firing => AlertDecision::Resolve,
                    default => AlertDecision::None,
                };
            }

            if ($decision === AlertDecision::Fire) {
                $alert->state = AlertState::Firing;
                $alert->fired_at = $at;
                $alert->resolved_at = null;
            }
            if ($decision === AlertDecision::Resolve) {
                $alert->state = AlertState::Ok;
                $alert->resolved_at = $at;
            }
            if ($result->firing) {
                $alert->severity = $check->severity();
                $alert->summary = mb_substr($result->summary('en'), 0, 500);
            }
            if ($decision->notifies()) {
                $alert->last_notified_at = $at;
                $alert->notify_count++;
            }
            $alert->save();

            return $decision;
        });
    }

    public function markTested(AlertCheck $check, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($check, $now): void {
            MonitoringAlert::query()->insertOrIgnore(['name' => $check->name(), 'state' => AlertState::Ok->value, 'notify_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
            MonitoringAlert::query()->whereKey($check->name())->update(['last_test_at' => $now, 'updated_at' => $now]);
        });
    }
}
