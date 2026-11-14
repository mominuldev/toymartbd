<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;

/**
 * An active, webhook-capable courier holds shipments untouched for `hours`, yet no webhook
 * from it has arrived in that time: the webhook URL or secret is wrong at the provider, or our
 * endpoint is rejecting it. The poll still covers status (so this is a warning), but slower.
 */
final readonly class CourierWebhookSilenceCheck implements AlertCheck
{
    public function name(): string
    {
        return 'courier_webhook_silence';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $hours = max(1, $settings->int('alerts.courier_webhook_silence.hours', 6));
        $cutoff = $now->subHours($hours);
        $withWebhooks = CourierProvider::query()->where('is_active', true)->where('supports_webhook', true)->pluck('code')->all();
        $silent = [];
        foreach (array_intersect(InFlightShipments::providersWithShipmentsOlderThan($cutoff), $withWebhooks) as $code) {
            $heard = CourierWebhookReceipt::query()->where('provider', $code)->where('created_at', '>=', $cutoff)->exists();
            if (! $heard) {
                $silent[] = $code;
            }
        }

        return $silent === []
            ? AlertResult::ok()
            : AlertResult::firing('monitoring.summary.courier_webhook_silence', ['providers' => implode(', ', $silent), 'hours' => $hours]);
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.courier_webhook_silence', ['providers' => 'test-courier', 'hours' => $settings->int('alerts.courier_webhook_silence.hours', 6)]);
    }
}
