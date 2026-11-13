<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Services\MessagingSettings;
use App\Support\Money;

/**
 * Per-segment SMS cost from Admin → Integrations → Messaging, falling back to
 * config/notifications.php. The rate is a PLACEHOLDER until the
 * owner signs a provider contract (docs/_research/agent4 §4.2 has observed list prices
 * only). BD providers charge one rate regardless of language; Bangla costs more purely
 * through segment count.
 */
final readonly class SmsTariff
{
    public function __construct(private MessagingSettings $settings) {}

    public function perSegment(): Money
    {
        return $this->settings->costPerSegment();
    }

    public function costFor(int $segments): Money
    {
        return $this->perSegment()->times($segments);
    }
}
