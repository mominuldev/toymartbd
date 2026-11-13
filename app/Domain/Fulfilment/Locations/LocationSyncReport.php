<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

/**
 * What one location sync did. `mappedInactive` counts mapped rows the provider no longer
 * returns — a booking through one is blocked, so staff must re-map them.
 */
final readonly class LocationSyncReport
{
    public function __construct(
        public int $cities,
        public int $zones,
        public int $areas,
        public int $created,
        public int $deactivated,
        public int $mappedInactive,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'cities' => $this->cities,
            'zones' => $this->zones,
            'areas' => $this->areas,
            'created' => $this->created,
            'deactivated' => $this->deactivated,
            'mapped_inactive' => $this->mappedInactive,
        ];
    }
}
