<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Pathao;

use App\Domain\Fulfilment\Locations\CourierLocationNode;
use Illuminate\Http\Client\Response;

/**
 * Fetches Pathao's Bangladesh location tree (research agent3 §5.3, agent4 §3.4).
 *
 *   cities: GET /countries/{1}/city-list  (Pathao's own plugin; falls back to /city-list)
 *   zones:  GET /zones/list               (flat, one call) — else /cities/{id}/zone-list per city
 *   areas:  GET /areas/list               (flat, ~6 MB)    — else /zones/{id}/area-list per zone
 *
 * Observed on the sandbox 2026-11-13: 64 cities, 1,323 zones, 38,520 areas; the flat lists
 * answer 200 and ignore `?page=` (no pagination). The per-city/per-zone walk is the
 * documented path and the fallback, rate-limited by `couriers.pathao.sync_delay_ms`.
 *
 * Bangladesh only: research saw Nepal in the sandbox (`city_id` 65 = Kathmandu). The sandbox
 * did not return it on 2026-11-13, but every zone whose city is not in the Bangladesh city
 * list is dropped regardless, and every area whose zone was dropped.
 */
final readonly class PathaoLocationSource
{
    public function __construct(private PathaoClient $client) {}

    /** @return list<CourierLocationNode> */
    public function fetch(): array
    {
        $cities = $this->cities();
        if ($cities === []) {
            throw PathaoException::malformed('city list');
        }

        $zones = $this->zones($cities);
        if ($zones === []) {
            throw PathaoException::malformed('zone list');
        }

        $nodes = [];
        foreach ($zones as $zoneId => $zone) {
            $nodes[] = new CourierLocationNode($zone['city'], $cities[$zone['city']], (string) $zoneId, $zone['name']);
        }

        foreach ($this->areas($zones) as $area) {
            $zone = $zones[$area['zone']];
            $nodes[] = new CourierLocationNode(
                $zone['city'], $cities[$zone['city']], $area['zone'], $zone['name'],
                $area['id'], $area['name'], $area['home'], $area['pickup'],
            );
        }

        return $nodes;
    }

    /** @return array<string, string> city id → name */
    private function cities(): array
    {
        $country = (int) config('couriers.pathao.country_id', 1);
        $response = $this->client->get("/countries/{$country}/city-list", 'city list');
        if (! $response->successful()) {
            $response = $this->client->get('/city-list', 'city list');
        }

        $cities = [];
        foreach (self::rows($response, 'data.data') as $row) {
            $id = self::id($row['city_id'] ?? null);
            $name = self::name($row['city_name'] ?? null);
            if ($id !== null && $name !== null) {
                $cities[$id] = $name;
            }
        }

        return $cities;
    }

    /**
     * @param  array<string, string>  $cities
     * @return array<string, array{city: string, name: string}> zone id → zone
     */
    private function zones(array $cities): array
    {
        $zones = [];
        $flat = $this->client->get('/zones/list', 'zone list');

        if ($flat->successful() && is_array($flat->json('data')) && array_is_list($flat->json('data'))) {
            foreach (self::rows($flat, 'data') as $row) {
                $id = self::id($row['id'] ?? null);
                $city = self::id($row['city_id'] ?? null);
                $name = self::name($row['name'] ?? null);
                if ($id !== null && $city !== null && $name !== null && isset($cities[$city])) {
                    $zones[$id] = ['city' => $city, 'name' => $name];
                }
            }

            return $zones;
        }

        foreach (array_keys($cities) as $city) {
            $this->pause();
            foreach (self::rows($this->client->get("/cities/{$city}/zone-list", 'zone list'), 'data.data') as $row) {
                $id = self::id($row['zone_id'] ?? null);
                $name = self::name($row['zone_name'] ?? null);
                if ($id !== null && $name !== null) {
                    $zones[$id] = ['city' => (string) $city, 'name' => $name];
                }
            }
        }

        return $zones;
    }

    /**
     * @param  array<string, array{city: string, name: string}>  $zones
     * @return list<array{id: string, zone: string, name: string, home: bool|null, pickup: bool|null}>
     */
    private function areas(array $zones): array
    {
        $areas = [];
        $flat = $this->client->get('/areas/list', 'area list');

        if ($flat->successful() && is_array($flat->json('data')) && array_is_list($flat->json('data'))) {
            foreach (self::rows($flat, 'data') as $row) {
                $id = self::id($row['id'] ?? null);
                $zone = self::id($row['zone_id'] ?? null);
                $name = self::name($row['name'] ?? null);
                if ($id !== null && $zone !== null && $name !== null && isset($zones[$zone])) {
                    $areas[] = ['id' => $id, 'zone' => $zone, 'name' => $name,
                        'home' => self::bool($row['is_home_delivery_available'] ?? null), 'pickup' => self::bool($row['is_pickup_available'] ?? null)];
                }
            }

            return $areas;
        }

        foreach (array_keys($zones) as $zone) {
            $this->pause();
            foreach (self::rows($this->client->get("/zones/{$zone}/area-list", 'area list'), 'data.data') as $row) {
                $id = self::id($row['area_id'] ?? null);
                $name = self::name($row['area_name'] ?? null);
                if ($id !== null && $name !== null) {
                    $areas[] = ['id' => $id, 'zone' => (string) $zone, 'name' => $name,
                        'home' => self::bool($row['home_delivery_available'] ?? null), 'pickup' => self::bool($row['pickup_available'] ?? null)];
                }
            }
        }

        return $areas;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(Response $response, string $path): array
    {
        if (! $response->successful()) {
            throw PathaoException::malformed('location list (HTTP '.$response->status().')');
        }

        $rows = $response->json($path);
        if (! is_array($rows)) {
            throw PathaoException::malformed('location list');
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    private function pause(): void
    {
        $ms = (int) config('couriers.pathao.sync_delay_ms', 250);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private static function id(mixed $value): ?string
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value)) ? (string) $value : null;
    }

    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $name === '' ? null : mb_substr($name, 0, 160);
    }

    private static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : (is_int($value) ? $value === 1 : null);
    }
}
