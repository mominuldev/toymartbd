<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

/**
 * `credit_notes.reason` — why an issued customer invoice was credited. Each reason has one
 * kind of source, and (`source_type`, `source_id`) is UNIQUE: one credit note per cause.
 *
 *  - `order_cancelled` — the order was cancelled after its invoice was issued; the source
 *    is the order and the credit note covers whatever of the invoice was still uncredited.
 *  - `refund` — a refund on a return was paid out; the source is the refund and the credit
 *    note covers the refunded lines (and the delivery charge, if refunded).
 *  - `order_edited` (P8 batch 3) — the order was edited down after its invoice was issued;
 *    the source is the edit's `order_events` row and the credit note covers the reduction
 *    (removed/reduced lines, a lower delivery charge). An increase in the same edit gets a
 *    supplementary invoice instead.
 *  - `courier_return` (P8 batch 3) — the courier returned the parcel to us (RTO); the source
 *    is the order and the credit note covers the invoice's whole remaining balance.
 */
enum CreditNoteReason: string
{
    case OrderCancelled = 'order_cancelled';
    case Refund = 'refund';
    case OrderEdited = 'order_edited';
    case CourierReturn = 'courier_return';

    public function sourceType(): string
    {
        return match ($this) {
            self::OrderCancelled => 'order_cancellation',
            self::Refund => 'refund',
            self::OrderEdited => 'order_edit',
            self::CourierReturn => 'courier_return',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
