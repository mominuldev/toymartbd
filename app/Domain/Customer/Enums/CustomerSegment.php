<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/**
 * RFM-style customer segments (PRD U-05 read side, roadmap Phase 8). Computed on read from
 * recency (`customers.last_order_at`), frequency (`customers.orders_count`, cancelled
 * excluded) and monetary value (delivered lifetime value from the ledger) — never stored.
 * Mutually exclusive, assigned in {@see self::precedence()} order; thresholds in
 * config/customers.php ({@see \App\Domain\Customer\Support\SegmentThresholds}).
 */
enum CustomerSegment: string
{
    case NoOrders = 'no_orders';
    case Lapsed = 'lapsed';
    case AtRisk = 'at_risk';
    case Vip = 'vip';
    case New = 'new';
    case Active = 'active';

    /** @return list<self> the order a customer is tested against; the first match wins */
    public static function precedence(): array
    {
        return [self::NoOrders, self::Lapsed, self::AtRisk, self::Vip, self::New, self::Active];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
