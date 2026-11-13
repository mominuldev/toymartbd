<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

/**
 * A courier driver whose provider has its own city/zone/area namespace that bookings must
 * use (Pathao). The sync mirrors it into `courier_locations`; nothing maps it automatically.
 */
interface ProvidesCourierLocations
{
    /**
     * Every zone and area the provider serves in Bangladesh, fetched over HTTP. Called from a
     * queued job, never inside a transaction (rule 4).
     *
     * @return list<CourierLocationNode>
     */
    public function fetchLocations(): array;
}
