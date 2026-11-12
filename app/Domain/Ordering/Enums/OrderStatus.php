<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * `orders.status` — the business workflow (DATABASE-DESIGN §3.4). The transition table
 * below is the order state machine (P4-ORD-03): any pair not listed is refused by
 * {@see \App\Domain\Ordering\Services\OrderStateMachine}.
 *
 *   pending ─┬─▶ confirmed ─▶ processing ─▶ ready_to_ship ─▶ shipped ─▶ delivered ─▶ completed
 *            │       ╰──────────╰────────────────╰──────────────▶ shipped
 *            ├─▶ on_hold (risk verification, or staff pause) ─▶ pending | confirmed | cancelled
 *            ╰─▶ cancelled   (from any pre-shipment state)
 *   shipped ─▶ returned | partially_returned  (courier return / partial delivery)
 *   delivered ─▶ returned | partially_returned ─▶ returned | completed
 *
 * Cancellation is pre-shipment only: once goods leave, the way back is a return. A
 * high-risk order is put on hold, never cancelled automatically (rules 23, 24b).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
    case PartiallyReturned = 'partially_returned';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::OnHold, self::Cancelled],
            self::OnHold => [self::Pending, self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::ReadyToShip, self::Shipped, self::OnHold, self::Cancelled],
            self::Processing => [self::ReadyToShip, self::Shipped, self::OnHold, self::Cancelled],
            self::ReadyToShip => [self::Shipped, self::OnHold, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned, self::PartiallyReturned],
            self::Delivered => [self::Completed, self::Returned, self::PartiallyReturned],
            self::PartiallyReturned => [self::Returned, self::Completed],
            self::Completed, self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Stock is still held as a reservation (not yet consumed by dispatch). */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Pending, self::OnHold, self::Confirmed, self::Processing, self::ReadyToShip], true);
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::Cancelled);
    }

    /** Whether the customer tracking timeline shows a move into this status. */
    public function isCustomerVisible(): bool
    {
        return ! in_array($this, [self::Pending, self::OnHold, self::Processing, self::ReadyToShip], true);
    }

    /** The `orders` timestamp column stamped when an order enters this status. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Confirmed => 'confirmed_at',
            self::ReadyToShip => 'packed_at',
            self::Shipped => 'shipped_at',
            self::Delivered => 'delivered_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
            self::Returned, self::PartiallyReturned => 'returned_at',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
