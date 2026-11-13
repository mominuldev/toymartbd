<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Enums;

/**
 * `purchases.status` (DATABASE-DESIGN §3.2) and its state machine:
 *
 *     draft ──▶ ordered ──▶ partially_received ──▶ received
 *       │          │                 │
 *       └──────────┴──▶ cancelled    └──▶ closed_short
 *
 * Only a draft is editable. Stock arrives only against an ordered or partially received
 * PO. Cancelling is refused once anything has been received: the units are on the shelf
 * and the supplier is owed for them. A partly received PO the supplier will not complete is
 * closed short instead (owner decision 2026-11-13): terminal, nothing more can be received,
 * and nothing is owed for the lines that never arrived.
 */
enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';
    case ClosedShort = 'closed_short';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::PartiallyReceived, self::Received, self::ClosedShort],
            self::Received, self::Cancelled, self::ClosedShort => [],
        }, true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
