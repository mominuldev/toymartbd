<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use Carbon\CarbonImmutable;

/**
 * An absolute delivery window (dates are checkable; "2–3 days" is not — A12.4).
 * Dates are Dhaka calendar dates at midnight Asia/Dhaka.
 */
final readonly class DeliveryEta
{
    public function __construct(
        public CarbonImmutable $dispatchDate,
        public CarbonImmutable $earliest,
        public CarbonImmutable $latest,
        public int $minDays,
        public int $maxDays,
        public string $cutoffTime,
        public bool $cutoffPassed,
    ) {}
}
