<?php

declare(strict_types=1);

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Enums\CustomerSegment;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The segment thresholds (config/customers.php — placeholders until the owner sets them)
 * and the one classification rule, so the list filter, the list tag and the analytics page
 * can never disagree. {@see \App\Domain\Reporting\Queries\Customers\CustomerSegmentSql}
 * is the same rule as SQL; a test holds the two together.
 */
final readonly class SegmentThresholds
{
    public function __construct(
        public Money $vipMinLifetimeValue,
        public int $atRiskAfterDays,
        public int $lapsedAfterDays,
    ) {}

    public static function fromConfig(): self
    {
        $vip = config('customers.segments.vip_min_lifetime_value', '15000.00');
        $atRisk = config('customers.segments.at_risk_after_days', 90);
        $lapsed = config('customers.segments.lapsed_after_days', 180);

        return new self(
            Money::of(is_string($vip) || is_int($vip) ? $vip : '15000.00'),
            is_int($atRisk) ? $atRisk : 90,
            is_int($lapsed) ? max(is_int($atRisk) ? $atRisk : 90, $lapsed) : 180,
        );
    }

    /** Last orders strictly before this instant are at risk (or lapsed). */
    public function atRiskBefore(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? DhakaDate::instant())->utc()->subDays($this->atRiskAfterDays);
    }

    public function lapsedBefore(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? DhakaDate::instant())->utc()->subDays($this->lapsedAfterDays);
    }

    public function classify(int $ordersCount, ?DateTimeInterface $lastOrderAt, Money $lifetimeValue, ?CarbonImmutable $now = null): CustomerSegment
    {
        if ($ordersCount === 0 || $lastOrderAt === null) {
            return CustomerSegment::NoOrders;
        }
        $last = CarbonImmutable::instance($lastOrderAt)->utc();
        if ($last->lessThan($this->lapsedBefore($now))) {
            return CustomerSegment::Lapsed;
        }
        if ($last->lessThan($this->atRiskBefore($now))) {
            return CustomerSegment::AtRisk;
        }
        if ($lifetimeValue->compareTo($this->vipMinLifetimeValue) >= 0) {
            return CustomerSegment::Vip;
        }

        return $ordersCount === 1 ? CustomerSegment::New : CustomerSegment::Active;
    }
}
