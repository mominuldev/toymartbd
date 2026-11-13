<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;

/**
 * Everything a courier driver needs to book one parcel. Built by
 * {@see \App\Domain\Fulfilment\Services\ShipmentRequestFactory} from the order's address
 * snapshot; a driver never reads the order itself. `courierLocation` is the explicitly
 * mapped `courier_locations` row for providers that need one (rule 32) — null for a
 * provider such as Steadfast that takes a free-text address only.
 */
final readonly class ShipmentRequest
{
    public function __construct(
        public int $shipmentId,
        public string $merchantOrderRef,
        public string $recipientName,
        public string $recipientPhone,
        public string $addressLine,
        public ?string $landmark,
        public ?int $districtId,
        public ?int $upazilaId,
        public ?CourierLocationRef $courierLocation,
        public Money $codAmount,
        public Money $declaredValue,
        public int $weightGrams,
        public int $itemCount,
        public string $itemDescription,
        public ?string $note = null,
    ) {}
}
