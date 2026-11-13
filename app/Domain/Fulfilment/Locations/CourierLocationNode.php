<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

/**
 * One zone (`areaId` null) or area from a courier's own location tree, as fetched — names
 * trimmed, ids as strings (provider ids are opaque; we never do arithmetic on one).
 */
final readonly class CourierLocationNode
{
    public function __construct(
        public string $cityId,
        public string $cityName,
        public string $zoneId,
        public string $zoneName,
        public ?string $areaId = null,
        public ?string $areaName = null,
        public ?bool $homeDeliveryAvailable = null,
        public ?bool $pickupAvailable = null,
    ) {}

    public function key(): string
    {
        return $this->cityId.':'.$this->zoneId.':'.($this->areaId ?? '-');
    }
}
