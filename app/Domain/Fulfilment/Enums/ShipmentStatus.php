<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Enums;

/**
 * `shipments.status` (DATABASE-DESIGN §3.6) — the normalised courier lifecycle every
 * driver's `statusMap()` translates into. The transition table is the shipment state
 * machine: a pair not listed is refused, so a late or replayed courier event can never
 * move a delivered parcel back to "in transit".
 *
 *   pending ─┬─▶ booked ─▶ picked_up ─▶ in_transit ─▶ out_for_delivery ─▶ delivered
 *            ├─▶ submitted (bulk: no consignment id yet, rule 24d) ─▶ booked
 *            ╰─▶ failed (booking refused) ─▶ pending (retry)
 *   any pre-delivery state ─▶ on_hold ─▶ back into the flow
 *   any pre-pickup state ─▶ cancelled
 *   in the courier's hands ─▶ delivered | partially_delivered | returned
 *
 * Couriers skip intermediate states (a webhook may jump booked → delivered), so every
 * later in-flight state is reachable from every earlier one.
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Booked = 'booked';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        $outcomes = [self::Delivered, self::PartiallyDelivered, self::Returned];

        return match ($this) {
            self::Pending => [self::Submitted, self::Booked, self::Failed, self::Cancelled],
            self::Failed => [self::Pending, self::Cancelled],
            self::Submitted => [self::Booked, self::PickedUp, self::InTransit, self::OutForDelivery, self::OnHold, self::Failed, self::Cancelled, ...$outcomes],
            self::Booked => [self::PickedUp, self::InTransit, self::OutForDelivery, self::OnHold, self::Cancelled, ...$outcomes],
            self::PickedUp => [self::InTransit, self::OutForDelivery, self::OnHold, ...$outcomes],
            self::InTransit => [self::OutForDelivery, self::OnHold, ...$outcomes],
            self::OutForDelivery => [self::InTransit, self::OnHold, ...$outcomes],
            self::OnHold => [self::Booked, self::PickedUp, self::InTransit, self::OutForDelivery, self::Cancelled, ...$outcomes],
            self::PartiallyDelivered => [self::Returned],
            self::Delivered, self::Returned, self::Cancelled => [],
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

    /**
     * An "open" shipment blocks requesting another for the same order. Only a failed or
     * cancelled one frees the order for a new shipment.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Failed, self::Cancelled], true);
    }

    /** The courier holds a consignment for this shipment (stock has left the warehouse). */
    public function isWithCourier(): bool
    {
        return in_array($this, [self::Booked, self::PickedUp, self::InTransit, self::OutForDelivery, self::OnHold], true);
    }

    /** The `shipments` timestamp column stamped when a shipment enters this status. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Submitted => 'pickup_requested_at',
            self::Booked => 'booked_at',
            self::PickedUp => 'picked_up_at',
            self::Delivered, self::PartiallyDelivered => 'delivered_at',
            self::Returned => 'returned_at',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
