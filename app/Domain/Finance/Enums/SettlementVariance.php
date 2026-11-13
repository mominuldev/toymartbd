<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * Why a settlement line does not simply match (`courier_settlement_items.variance_type`).
 * Flagged for a person; NEVER adjusted automatically. Only `short` may be accepted (book what
 * the courier actually paid); every other variance can only be excluded and taken up with the
 * courier — the receivable stays open.
 */
enum SettlementVariance: string
{
    case None = 'none';
    /** Paid less than our shipment says (COD collected or net after charges). */
    case Short = 'short';
    /** Paid more than our shipment says. */
    case Over = 'over';
    /** A delivered COD parcel in the statement period that the courier did not pay. */
    case Missing = 'missing';
    /** No shipment of ours with this consignment id at this courier. */
    case UnknownConsignment = 'unknown_consignment';
    /** Our shipment is not delivered (or returned): nothing is collectable yet. */
    case NotDelivered = 'not_delivered';
    /** This shipment was already booked in an earlier settlement. */
    case AlreadySettled = 'already_settled';
    /** The consignment appears twice in this statement. */
    case DuplicateLine = 'duplicate_line';
    /** More than the order's outstanding receivable (e.g. a manual COD receipt already recorded). */
    case ExceedsReceivable = 'exceeds_receivable';

    public function acceptable(): bool
    {
        return $this === self::Short;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
