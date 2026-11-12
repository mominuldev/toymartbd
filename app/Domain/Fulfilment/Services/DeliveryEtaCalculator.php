<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\DTOs\DeliveryEta;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Delivery dates (03-storefront-spec A12.4): an order placed before the cut-off
 * (5:00 pm Asia/Dhaka by default) leaves the same working day, after it the next; the
 * window is then `[min, max]` working days from dispatch. Non-working weekdays (Friday)
 * and published holidays are skipped for both dispatch and delivery.
 */
final readonly class DeliveryEtaCalculator
{
    public function __construct(private DeliverySettings $settings) {}

    public function window(int $minDays, int $maxDays, ?DateTimeInterface $now = null): DeliveryEta
    {
        $local = DhakaDate::local($now ?? CarbonImmutable::now());
        [$hour, $minute] = $this->settings->cutoff();
        $cutoffPassed = $local->format('H:i') >= sprintf('%02d:%02d', $hour, $minute);

        $dispatch = $local->startOfDay();
        if ($cutoffPassed) {
            $dispatch = $dispatch->addDay();
        }
        while (! $this->isWorkingDay($dispatch)) {
            $dispatch = $dispatch->addDay();
        }

        return new DeliveryEta(
            dispatchDate: $dispatch,
            earliest: $this->addWorkingDays($dispatch, $minDays),
            latest: $this->addWorkingDays($dispatch, max($minDays, $maxDays)),
            minDays: $minDays,
            maxDays: max($minDays, $maxDays),
            cutoffTime: $this->settings->cutoffTime(),
            cutoffPassed: $cutoffPassed,
        );
    }

    private function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $date = $from;
        for ($added = 0; $added < $days;) {
            $date = $date->addDay();
            if ($this->isWorkingDay($date)) {
                $added++;
            }
        }

        return $date;
    }

    private function isWorkingDay(CarbonImmutable $date): bool
    {
        return ! in_array($date->isoWeekday(), $this->settings->nonWorkingWeekdays(), true)
            && ! in_array($date->format('Y-m-d'), $this->settings->holidays(), true);
    }
}
