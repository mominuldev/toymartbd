<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Customers;

use App\Domain\Customer\Enums\CustomerSegment;
use App\Domain\Customer\Support\SegmentThresholds;
use Carbon\CarbonImmutable;

/**
 * {@see SegmentThresholds::classify()} as one SQL CASE over `customers` left-joined to
 * {@see CustomerLedgerValues::subquery()} as `ltv`. Cut-off instants are bound as UTC
 * strings, so it runs the same on MySQL and SQLite.
 */
final readonly class CustomerSegmentSql
{
    public function __construct(private SegmentThresholds $thresholds, private CarbonImmutable $now) {}

    public static function make(?CarbonImmutable $now = null): self
    {
        return new self(SegmentThresholds::fromConfig(), $now ?? CarbonImmutable::now('UTC'));
    }

    /** @return array{0: string, 1: list<string>} the CASE expression and its bindings */
    public function expression(): array
    {
        $sql = 'CASE'
            .' WHEN customers.orders_count = 0 OR customers.last_order_at IS NULL THEN ?'
            .' WHEN customers.last_order_at < ? THEN ?'
            .' WHEN customers.last_order_at < ? THEN ?'
            .' WHEN COALESCE(ltv.lifetime_value, 0) >= ? THEN ?'
            .' WHEN customers.orders_count = 1 THEN ?'
            .' ELSE ? END';

        return [$sql, [
            CustomerSegment::NoOrders->value,
            $this->thresholds->lapsedBefore($this->now)->format('Y-m-d H:i:s'), CustomerSegment::Lapsed->value,
            $this->thresholds->atRiskBefore($this->now)->format('Y-m-d H:i:s'), CustomerSegment::AtRisk->value,
            $this->thresholds->vipMinLifetimeValue->toDecimal(), CustomerSegment::Vip->value,
            CustomerSegment::New->value,
            CustomerSegment::Active->value,
        ]];
    }

    public function thresholds(): SegmentThresholds
    {
        return $this->thresholds;
    }
}
