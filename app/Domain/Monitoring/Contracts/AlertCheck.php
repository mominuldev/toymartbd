<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;

/**
 * One business alert (SYSTEM-ARCHITECTURE §11). Read-only: a check never writes anything —
 * App\Domain\Monitoring\Actions\RecordAlertOutcome owns the state and the de-duplication.
 */
interface AlertCheck
{
    /** Stable machine name, `snake_case`; the argument of `toymart:alerts:test`. */
    public function name(): string;

    public function severity(): AlertSeverity;

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult;

    /** A representative firing result for `toymart:alerts:test` — no data is read or faked. */
    public function sample(MonitoringSettings $settings): AlertResult;
}
