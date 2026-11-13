<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Steadfast;

use App\Domain\Fulfilment\Enums\ShipmentStatus;

/**
 * Every documented Steadfast `delivery_status`, normalised (docs/api/couriers.md
 * "Steadfast" holds the same table with the reasoning; research §6.5).
 *
 * The eleven values of the guide's verbatim table come first. Then the six
 * return-progress values only `/status_with_return_status_by_cid` reports, and
 * `exceptional`, which only the newer guide recreation lists — handled defensively.
 * `in_transit` is NOT a Steadfast value (fabricated in unreliable sources) and is
 * deliberately absent.
 *
 * Choices that are judgement, not documentation:
 *  - `pending` ("not delivered or cancelled yet") → in_transit: Steadfast reports no
 *    separate pickup state, so pending covers everything between review and outcome.
 *  - `*_approval_pending` is NOT final: Steadfast has not yet confirmed the rider's
 *    claim, so delivered/partial → out_for_delivery and cancelled/unknown → on_hold.
 *    Revenue is recognised only on the confirmed `delivered` (rule 10).
 *  - `cancelled` ("cancelled and balance updated") comes after the parcel was with the
 *    courier, so it is a courier return → returned (§4.4), not a pre-pickup cancel.
 *  - Anything unrecognised → on_hold for a human, never a guess.
 */
final class SteadfastStatusMap
{
    /** @var array<string, ShipmentStatus> */
    public const array MAP = [
        // The guide's verbatim table (11 values).
        'in_review' => ShipmentStatus::Booked,
        'pending' => ShipmentStatus::InTransit,
        'hold' => ShipmentStatus::OnHold,
        'delivered_approval_pending' => ShipmentStatus::OutForDelivery,
        'partial_delivered_approval_pending' => ShipmentStatus::OutForDelivery,
        'cancelled_approval_pending' => ShipmentStatus::OnHold,
        'unknown_approval_pending' => ShipmentStatus::OnHold,
        'delivered' => ShipmentStatus::Delivered,
        'partial_delivered' => ShipmentStatus::PartiallyDelivered,
        'cancelled' => ShipmentStatus::Returned,
        'unknown' => ShipmentStatus::OnHold,
        // Return progress (status_with_return_status_by_cid only).
        'partial_delivered_return_processing' => ShipmentStatus::PartiallyDelivered,
        'partial_delivered_return_rider_assigned' => ShipmentStatus::PartiallyDelivered,
        'partial_delivered_return_received' => ShipmentStatus::PartiallyDelivered,
        'cancelled_return_processing' => ShipmentStatus::Returned,
        'cancelled_return_rider_assigned' => ShipmentStatus::Returned,
        'cancelled_return_received' => ShipmentStatus::Returned,
        // Newer-guide recreation only.
        'exceptional' => ShipmentStatus::OnHold,
    ];

    /** The status values in the guide's verbatim table — the ones a test must cover. */
    public const array DOCUMENTED = [
        'pending', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending',
        'unknown_approval_pending', 'delivered', 'partial_delivered', 'cancelled', 'hold', 'in_review', 'unknown',
    ];

    /**
     * `status_by_trackingcode` may answer "in review" with a space; case and separators
     * are normalised before lookup.
     */
    public static function normalise(string $raw): string
    {
        return (string) preg_replace('/[\s\-]+/', '_', strtolower(trim($raw)));
    }

    public static function isKnown(string $raw): bool
    {
        return isset(self::MAP[self::normalise($raw)]);
    }

    public static function toShipmentStatus(string $raw): ShipmentStatus
    {
        return self::MAP[self::normalise($raw)] ?? ShipmentStatus::OnHold;
    }
}
