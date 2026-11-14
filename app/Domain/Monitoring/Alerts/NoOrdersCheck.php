<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Domain\Ordering\Models\Order;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

/**
 * Zero orders placed over the last N BUSINESS hours, Dhaka time (§11: "zero orders in 3
 * daytime hours"). Night hours are skipped when counting back, so 10:00 looks back to 21:00
 * the evening before with the default 09:00–23:00 day. Silent outside business hours and
 * before the store has ever taken an order (pre-launch). Usually means checkout is broken.
 */
final readonly class NoOrdersCheck implements AlertCheck
{
    public function name(): string
    {
        return 'no_orders';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Critical;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $hours = max(1, $settings->int('alerts.no_orders.hours', 3));
        [$open, $close] = $this->businessDay($settings);
        $local = DhakaDate::local($now);

        if (! $this->withinBusinessHours($local, $open, $close)) {
            return AlertResult::notApplicable('outside business hours');
        }

        $since = self::windowStart($local, $hours, $open, $close)->utc();
        if (! Order::query()->where('created_at', '<', $since)->exists()) {
            return AlertResult::notApplicable('no orders before the window (pre-launch)');
        }

        $count = Order::query()->where('created_at', '>=', $since)->count();

        return $count === 0
            ? AlertResult::firing('monitoring.summary.no_orders', ['hours' => $hours, 'since' => DhakaDate::local($since)->format('Y-m-d H:i')])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.no_orders', ['hours' => $settings->int('alerts.no_orders.hours', 3), 'since' => 'TEST']);
    }

    /**
     * Walks back $hours of business time from $now (Dhaka). Pure; unit-tested.
     *
     * @param  array{0: int, 1: int}  $open  [hour, minute]
     * @param  array{0: int, 1: int}  $close  [hour, minute]
     */
    public static function windowStart(CarbonImmutable $now, int $hours, array $open, array $close): CarbonImmutable
    {
        $remaining = $hours * 60;
        $cursor = $now;

        for ($guard = 0; $remaining > 0 && $guard < 400; $guard++) {
            $dayOpen = $cursor->setTime($open[0], $open[1]);
            $dayClose = $cursor->setTime($close[0], $close[1]);
            if ($cursor->greaterThan($dayClose)) {
                $cursor = $dayClose;
            }
            $available = (int) $dayOpen->diffInMinutes($cursor, true);
            if ($cursor->lessThanOrEqualTo($dayOpen)) {
                $available = 0;
            }
            $take = min($remaining, $available);
            $cursor = $cursor->subMinutes($take);
            $remaining -= $take;
            if ($remaining > 0) {
                $cursor = $cursor->subDay()->setTime($close[0], $close[1]);
            }
        }

        return $cursor;
    }

    /** @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} */
    private function businessDay(MonitoringSettings $settings): array
    {
        return [
            self::parseTime($settings->string('alerts.no_orders.business_start', '09:00'), [9, 0]),
            self::parseTime($settings->string('alerts.no_orders.business_end', '23:00'), [23, 0]),
        ];
    }

    /**
     * @param  array{0: int, 1: int}  $open
     * @param  array{0: int, 1: int}  $close
     */
    private function withinBusinessHours(CarbonImmutable $local, array $open, array $close): bool
    {
        $minute = $local->hour * 60 + $local->minute;

        return $minute >= $open[0] * 60 + $open[1] && $minute < $close[0] * 60 + $close[1];
    }

    /**
     * @param  array{0: int, 1: int}  $fallback
     * @return array{0: int, 1: int}
     */
    private static function parseTime(string $value, array $fallback): array
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m) === 1 ? [(int) $m[1], (int) $m[2]] : $fallback;
    }
}
