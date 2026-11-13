<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Actions\MapCourierLocation;
use App\Domain\Fulfilment\Actions\UnmapCourierLocation;
use App\Domain\Fulfilment\Jobs\SyncCourierLocations;
use App\Domain\Fulfilment\Locations\CourierLocationResolver;
use App\Domain\Fulfilment\Locations\CourierLocationSuggester;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\CourierLocation\CourierLocationLookupRequest;
use App\Http\Requests\Admin\CourierLocation\MapCourierLocationRequest;
use App\Http\Requests\Admin\CourierLocation\SyncCourierLocationsRequest;
use App\Http\Requests\Admin\CourierLocation\UnmapCourierLocationRequest;
use App\Http\Resources\Admin\CourierLocation\CourierLocationOverviewResource;
use App\Http\Resources\Admin\CourierLocation\CourierLocationResolutionResource;
use App\Http\Resources\Admin\CourierLocation\CourierLocationResource;
use App\Http\Resources\Admin\CourierLocation\CourierPlaceResource;
use App\Http\Resources\Admin\CourierLocation\CourierPlaceSuggestionResource;
use App\Http\Resources\Admin\CourierLocation\CourierZoneSuggestionResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Courier location mapping (P6, DATABASE-DESIGN §3.6, admin checklist §2.3 "location
 * mapping"). The courier's own zones are mirrored by a sync; a staff member maps them to our
 * districts/upazilas one by one. Nothing here maps by name: suggestions are returned as
 * suggestions and only `PUT …/mapping` writes a mapping (rule 32).
 *
 * `{provider}` is a `courier_providers.code` whose provider requires location mapping
 * (Pathao); any other code is a 404.
 */
final class CourierLocationController extends ApiController
{
    public const int PER_PAGE = 50;

    /** Orders not yet handed to a courier: these are what an unmapped place blocks. */
    private const array WAITING = [OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip];

    /** `GET /courier-locations/{provider}` — totals, last sync, and the courier's cities; needs shipments.view. */
    public function overview(string $provider): CourierLocationOverviewResource
    {
        $this->authorize('viewAny', CourierLocation::class);
        $p = $this->provider($provider);

        $base = CourierLocation::query()->where('provider_id', $p->id);
        $cities = (clone $base)->where('is_active', true)->whereNull('external_area_id')
            ->select('external_city_id', 'external_city_name')
            ->selectRaw('COUNT(*) as zones, SUM(CASE WHEN is_mapped THEN 1 ELSE 0 END) as mapped')
            ->groupBy('external_city_id', 'external_city_name')
            ->orderBy('external_city_name')
            ->get()
            ->map(static fn (CourierLocation $c): array => [
                'id' => $c->external_city_id,
                'name' => $c->external_city_name,
                'zones' => (int) $c->getAttribute('zones'),
                'mapped' => (int) $c->getAttribute('mapped'),
            ])->values()->all();

        /** @var array{cities: int, zones: int, areas: int, created: int, deactivated: int, mapped_inactive: int, finished_at: string}|null $lastSync */
        $lastSync = Cache::get(SyncCourierLocations::lastReportKey($p->code));

        return new CourierLocationOverviewResource([
            'provider' => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'name_bn' => $p->name_bn],
            'zones' => (clone $base)->where('is_active', true)->whereNull('external_area_id')->count(),
            'areas' => (clone $base)->where('is_active', true)->whereNotNull('external_area_id')->count(),
            'mapped' => (clone $base)->where('is_active', true)->where('is_mapped', true)->count(),
            'mapped_inactive' => (clone $base)->where('is_active', false)->where('is_mapped', true)->count(),
            'last_sync' => is_array($lastSync) ? $lastSync : null,
            'cities' => $cities,
        ]);
    }

    /**
     * `GET /courier-locations/{provider}/locations` — the courier's zones and areas, cursor-paginated; needs shipments.view.
     *
     * Filters: `filter[city]` (courier city id), `filter[status]` = `mapped|unmapped|inactive` (default: every active row),
     * `filter[level]` = `zone|area`, `filter[search]` (zone or area name contains). Sorted by city, zone, area. Anything else is a 400.
     */
    #[QueryParameter('filter[city]', "The courier's own city id.", type: 'string')]
    #[QueryParameter('filter[status]', 'Mapped, unmapped (both active only) or inactive rows.', type: "'mapped'|'unmapped'|'inactive'")]
    #[QueryParameter('filter[level]', 'Only zones or only areas.', type: "'zone'|'area'")]
    #[QueryParameter('filter[search]', 'Case-insensitive "contains" on zone or area name.', type: 'string')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or an invalid filter value (`bad-request`)')]
    public function index(Request $request, string $provider): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourierLocation::class);
        $p = $this->provider($provider);

        $status = $request->input('filter.status');
        $rows = QueryBuilder::for(CourierLocation::query()->where('provider_id', $p->id)->with(['district', 'upazila']), $request)
            ->allowedFilters(
                AllowedFilter::exact('city', 'external_city_id'),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    match ($value) {
                        'mapped' => $query->where('is_mapped', true),
                        'unmapped' => $query->where('is_mapped', false),
                        'inactive' => $query,
                        default => throw new BadRequestHttpException('filter[status] must be one of: mapped, unmapped, inactive.'),
                    };
                }),
                AllowedFilter::callback('level', static function (Builder $query, mixed $value): void {
                    match ($value) {
                        'zone' => $query->whereNull('external_area_id'),
                        'area' => $query->whereNotNull('external_area_id'),
                        default => throw new BadRequestHttpException('filter[level] must be one of: zone, area.'),
                    };
                }),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                    $like = '%'.addcslashes($term, '\\%_').'%';
                    $query->where(static fn (Builder $q) => $q->where('external_zone_name', 'like', $like)->orWhere('external_area_name', 'like', $like));
                }),
            )
            ->where('is_active', $status !== 'inactive')
            ->orderBy('external_city_name')
            ->orderBy('external_zone_name')
            ->orderByRaw('external_area_id IS NOT NULL')
            ->orderBy('external_area_name')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return CourierLocationResource::collection($rows);
    }

    /**
     * `GET /courier-locations/{provider}/needs-mapping` — OUR places that orders waiting to ship
     * are addressed to and that have nothing mapped for this courier, most orders first. A
     * booking through this courier into any of them is blocked. Needs shipments.view.
     */
    public function needsMapping(string $provider): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CourierLocation::class);
        $p = $this->provider($provider);

        $waiting = $this->waitingOrders();
        $mapped = $this->mappedCounts($p);

        $places = [];
        foreach ($waiting as $key => $count) {
            if (($mapped[$key] ?? 0) > 0) {
                continue;
            }
            [$district, $upazila] = array_map(static fn (string $id): ?int => $id === '' ? null : (int) $id, explode(':', $key));
            if ($district !== null) {
                $places[] = ['district_id' => $district, 'upazila_id' => $upazila, 'waiting_orders' => $count, 'mapped_count' => 0];
            }
        }
        usort($places, static fn (array $a, array $b): int => $b['waiting_orders'] <=> $a['waiting_orders'] ?: $a['district_id'] <=> $b['district_id']);

        return CourierPlaceResource::collection($this->named(array_slice($places, 0, 200)));
    }

    /** `GET /courier-locations/{provider}/places?q=` — the place picker: districts and upazilas matching `q` (English or Bangla). */
    public function places(CourierLocationLookupRequest $request, string $provider): AnonymousResourceCollection
    {
        $p = $this->provider($provider);
        $term = trim($request->string('q')->toString());
        if ($term === '') {
            return CourierPlaceResource::collection([]);
        }
        $like = '%'.addcslashes($term, '\\%_').'%';

        $waiting = $this->waitingOrders();
        $mapped = $this->mappedCounts($p);
        $places = [];

        foreach (District::query()->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('name_bn', 'like', $like))->orderBy('name')->limit(10)->get() as $d) {
            $places[] = ['district_id' => $d->id, 'upazila_id' => null];
        }
        foreach (Upazila::query()->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('name_bn', 'like', $like))->orderBy('name')->limit(20)->get() as $u) {
            $places[] = ['district_id' => $u->district_id, 'upazila_id' => $u->id];
        }

        $places = array_map(static fn (array $pl): array => [
            ...$pl,
            'waiting_orders' => $waiting[$pl['district_id'].':'.($pl['upazila_id'] ?? '')] ?? 0,
            'mapped_count' => $mapped[$pl['district_id'].':'.($pl['upazila_id'] ?? '')] ?? 0,
        ], $places);

        return CourierPlaceResource::collection($this->named($places));
    }

    /**
     * `GET /courier-locations/{provider}/suggestions?district_id=&upazila_id=` — SUGGESTED courier
     * zones for one of our places (name similarity; confirm before mapping). Needs shipments.view.
     */
    #[OpenApiResponse(422, '`district_id` missing or unknown')]
    public function suggestZones(CourierLocationLookupRequest $request, string $provider, CourierLocationSuggester $suggester): AnonymousResourceCollection
    {
        $p = $this->provider($provider);
        $district = District::query()->findOrFail($request->districtId() ?? 0);
        $upazila = $request->upazilaId() === null ? null : Upazila::query()->where('district_id', $district->id)->findOrFail($request->upazilaId());

        return CourierZoneSuggestionResource::collection($suggester->zonesFor($p, $district, $upazila));
    }

    /** `GET /courier-locations/{provider}/locations/{location}/suggestions` — SUGGESTED places of ours for one courier row. */
    public function suggestPlaces(string $provider, CourierLocation $location, CourierLocationSuggester $suggester): AnonymousResourceCollection
    {
        $this->authorize('view', $location);
        $this->owned($this->provider($provider), $location);

        return CourierPlaceSuggestionResource::collection($suggester->upazilasFor($location));
    }

    /**
     * `GET /courier-locations/{provider}/resolve?district_id=&upazila_id=&area=` — what a booking to
     * this address would send: resolved ids, an ambiguous candidate list, or unmapped (blocked).
     */
    public function resolve(CourierLocationLookupRequest $request, string $provider, CourierLocationResolver $resolver): CourierLocationResolutionResource
    {
        $p = $this->provider($provider);

        return new CourierLocationResolutionResource($resolver->resolve($p, $request->districtId(), $request->upazilaId(), $request->string('area')->toString()));
    }

    /** `PUT /courier-locations/{provider}/locations/{location}/mapping` — map a zone/area to our place; needs shipments.manage_locations. */
    #[OpenApiResponse(422, 'Upazila outside the district, or the courier no longer serves the row')]
    public function map(MapCourierLocationRequest $request, string $provider, CourierLocation $location, MapCourierLocation $map): CourierLocationResource
    {
        $this->owned($this->provider($provider), $location);
        $row = $map->handle($location, $request->districtId(), $request->upazilaId(), (int) $request->user()?->getKey());

        return new CourierLocationResource($row->load(['district', 'upazila']));
    }

    /** `DELETE /courier-locations/{provider}/locations/{location}/mapping` — clear a mapping; needs shipments.manage_locations. */
    public function unmap(UnmapCourierLocationRequest $request, string $provider, CourierLocation $location, UnmapCourierLocation $unmap): CourierLocationResource
    {
        $this->owned($this->provider($provider), $location);

        return new CourierLocationResource($unmap->handle($location, (int) $request->user()?->getKey())->load(['district', 'upazila']));
    }

    /** `POST /courier-locations/{provider}/sync` — queue a fresh pull of the courier's tree (202); needs shipments.manage_locations. */
    public function sync(SyncCourierLocationsRequest $request, string $provider): Response
    {
        SyncCourierLocations::dispatch($this->provider($provider)->code);

        return new Response('', 202);
    }

    private function provider(string $code): CourierProvider
    {
        $provider = CourierProvider::query()->where('code', $code)->first();
        if ($provider === null || ! $provider->requires_location_mapping) {
            throw new NotFoundHttpException('No courier with a location tree has this code.');
        }

        return $provider;
    }

    private function owned(CourierProvider $provider, CourierLocation $location): void
    {
        if ($location->provider_id !== $provider->id) {
            throw new NotFoundHttpException('This location belongs to another courier.');
        }
    }

    /** @return array<string, int> "{district}:{upazila|''}" → orders waiting to ship there */
    private function waitingOrders(): array
    {
        $counts = [];
        Order::query()
            ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, self::WAITING))
            ->whereNotNull('shipping_district_id')
            ->select('shipping_district_id', 'shipping_upazila_id', DB::raw('COUNT(*) as orders'))
            ->groupBy('shipping_district_id', 'shipping_upazila_id')
            ->toBase()
            ->get()
            ->each(static function (object $row) use (&$counts): void {
                /** @var object{shipping_district_id: int|string, shipping_upazila_id: int|string|null, orders: int|string} $row */
                $counts[$row->shipping_district_id.':'.($row->shipping_upazila_id ?? '')] = (int) $row->orders;
            });

        return $counts;
    }

    /** @return array<string, int> "{district}:{upazila|''}" → active mapped rows */
    private function mappedCounts(CourierProvider $provider): array
    {
        $counts = [];
        CourierLocation::query()->mapped()->where('provider_id', $provider->id)
            ->select('district_id', 'upazila_id', DB::raw('COUNT(*) as rows_mapped'))
            ->groupBy('district_id', 'upazila_id')
            ->toBase()
            ->get()
            ->each(static function (object $row) use (&$counts): void {
                /** @var object{district_id: int|string, upazila_id: int|string|null, rows_mapped: int|string} $row */
                $counts[$row->district_id.':'.($row->upazila_id ?? '')] = (int) $row->rows_mapped;
            });

        return $counts;
    }

    /**
     * @param  list<array{district_id: int, upazila_id: int|null, waiting_orders: int, mapped_count: int}>  $places
     * @return list<array{district_id: int, district: string, district_bn: string, upazila_id: int|null, upazila: string|null, upazila_bn: string|null, waiting_orders: int, mapped_count: int}>
     */
    private function named(array $places): array
    {
        $districts = District::query()->whereIn('id', array_column($places, 'district_id'))->get()->keyBy('id');
        $upazilas = Upazila::query()->whereIn('id', array_filter(array_column($places, 'upazila_id')))->get()->keyBy('id');

        $out = [];
        foreach ($places as $pl) {
            $d = $districts->get($pl['district_id']);
            $u = $pl['upazila_id'] === null ? null : $upazilas->get($pl['upazila_id']);
            if ($d === null) {
                continue;
            }
            $out[] = [
                'district_id' => $d->id,
                'district' => $d->name,
                'district_bn' => $d->name_bn,
                'upazila_id' => $u?->id,
                'upazila' => $u?->name,
                'upazila_bn' => $u?->name_bn,
                'waiting_orders' => $pl['waiting_orders'],
                'mapped_count' => $pl['mapped_count'],
            ];
        }

        return $out;
    }
}
