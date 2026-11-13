<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

/**
 * A provider's own city/zone/area ids for one delivery address, taken from an explicitly
 * mapped `courier_locations` row — never derived from a district or upazila name (rule 32).
 */
final readonly class CourierLocationRef
{
    public function __construct(
        public ?string $externalCityId,
        public ?string $externalZoneId,
        public ?string $externalAreaId,
    ) {}
}
