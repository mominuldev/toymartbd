<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Support\Money;

/**
 * Per-segment SMS cost from config/notifications.php. The rate is a PLACEHOLDER until the
 * owner signs a provider contract (docs/_research/agent4 §4.2 has observed list prices
 * only). BD providers charge one rate regardless of language; Bangla costs more purely
 * through segment count.
 */
final class SmsTariff
{
    public function perSegment(): Money
    {
        $rate = config('notifications.sms.cost_per_segment', '0.00');

        return Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00');
    }

    public function costFor(int $segments): Money
    {
        return $this->perSegment()->times($segments);
    }
}
