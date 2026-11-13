<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Locations\CourierLocationNode;
use App\Domain\Fulfilment\Locations\LocationSyncReport;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mirror a fetched courier location tree into `courier_locations` (DATABASE-DESIGN §3.6).
 * The HTTP fetch happened before this runs (rule 4); this is the transaction.
 *
 * Idempotent: rows upsert on `(provider_id, external_key)`, and an upsert only ever writes
 * the provider's own fields — names, delivery flags, `is_active`, `synced_at`. It NEVER
 * writes `district_id`, `upazila_id` or `is_mapped`: a sync cannot map anything (rule 32),
 * and it cannot undo a human's mapping. A row missing from this pull is deactivated, not
 * deleted, so a provider-side glitch does not erase mappings.
 */
final readonly class ImportCourierLocations
{
    private const int CHUNK = 500;

    /** @param  list<CourierLocationNode>  $nodes */
    public function handle(CourierProvider $provider, array $nodes, ?CarbonImmutable $at = null): LocationSyncReport
    {
        if ($nodes === []) {
            throw new InvalidArgumentException('Refusing to sync an empty location tree: it would deactivate every row.');
        }

        $at ??= CarbonImmutable::now();
        $stamp = $at->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($nodes as $node) {
            $rows[$node->key()] = [
                'provider_id' => $provider->id,
                'external_key' => $node->key(),
                'external_city_id' => $node->cityId,
                'external_city_name' => $node->cityName,
                'external_zone_id' => $node->zoneId,
                'external_zone_name' => $node->zoneName,
                'external_area_id' => $node->areaId,
                'external_area_name' => $node->areaName,
                'home_delivery_available' => $node->homeDeliveryAvailable,
                'pickup_available' => $node->pickupAvailable,
                'is_active' => true,
                'synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        return DB::transaction(static function () use ($provider, $rows, $stamp): LocationSyncReport {
            $before = CourierLocation::query()->where('provider_id', $provider->id)->count();

            foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
                CourierLocation::query()->upsert($chunk, ['provider_id', 'external_key'], [
                    'external_city_id', 'external_city_name', 'external_zone_id', 'external_zone_name',
                    'external_area_id', 'external_area_name', 'home_delivery_available', 'pickup_available',
                    'is_active', 'synced_at', 'updated_at',
                ]);
            }

            // By key, not by timestamp: two runs inside one second must still deactivate.
            $missing = array_diff(
                CourierLocation::query()->where('provider_id', $provider->id)->where('is_active', true)->pluck('external_key')->all(),
                array_keys($rows),
            );
            $deactivated = 0;
            foreach (array_chunk(array_values($missing), self::CHUNK) as $keys) {
                $deactivated += CourierLocation::query()->where('provider_id', $provider->id)->whereIn('external_key', $keys)
                    ->update(['is_active' => false, 'updated_at' => $stamp]);
            }

            $after = CourierLocation::query()->where('provider_id', $provider->id)->count();
            $zones = array_filter($rows, static fn (array $r): bool => $r['external_area_id'] === null);

            return new LocationSyncReport(
                cities: count(array_unique(array_column($rows, 'external_city_id'))),
                zones: count($zones),
                areas: count($rows) - count($zones),
                created: $after - $before,
                deactivated: $deactivated,
                mappedInactive: CourierLocation::query()->where('provider_id', $provider->id)->where('is_mapped', true)->where('is_active', false)->count(),
            );
        });
    }
}
