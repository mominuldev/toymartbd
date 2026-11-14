<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Customer\Models\District;
use App\Domain\Inventory\Actions\CreateStockLocation;
use App\Domain\Inventory\Actions\UpdateStockLocation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\StoreSettings\StoreStockLocationRequest;
use App\Http\Requests\Admin\StoreSettings\UpdateStockLocationRequest;
use App\Http\Resources\Admin\StoreSettings\StoreLocationDistrictResource;
use App\Http\Resources\Admin\StoreSettings\StoreLocationResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Store settings › Branches & warehouses (Figma B · 38 241:50827): the stock locations with
 * their type, address (rule 31) and facts line. Read with settings.view — this is store
 * configuration, and staff who only need stock see locations on the inventory screens
 * (inventory.view) already; every write needs settings.update.
 *
 * Locations are never deleted (their movements are ledger history, rule 8); switching one
 * off is an edit with `is_active: false`.
 */
final class StoreLocationController extends ApiController
{
    /** `GET /settings/locations` — every location, active and inactive, default first. Not paginated (a handful of rows). */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize(Permission::SettingsView->value);

        $holding = StockLevel::query()
            ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0)->orWhere('incoming', '!=', 0))
            ->distinct()
            ->pluck('location_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $locations = StockLocation::query()
            ->with(['division', 'district', 'upazila'])
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get()
            ->each(static fn (StockLocation $l) => $l->setAttribute('holds_stock', in_array($l->id, $holding, true)));

        return StoreLocationResource::collection($locations);
    }

    /** `GET /settings/locations/districts` — the 64 districts with division and upazilas, for the address picker. */
    public function districts(): AnonymousResourceCollection
    {
        $this->authorize(Permission::SettingsView->value);

        return StoreLocationDistrictResource::collection(District::query()->with(['division', 'upazilas'])->orderBy('name')->get());
    }

    /** `POST /settings/locations` — 201 with the new location. */
    public function store(StoreStockLocationRequest $request, CreateStockLocation $create): JsonResponse
    {
        return self::resource($create->handle($request->toData()))->response()->setStatusCode(201);
    }

    /** `PUT /settings/locations/{location}` — edit, or switch off with `is_active: false`. */
    #[OpenApiResponse(409, 'Switching off the default location, or one that still holds stock (`conflict`)')]
    public function update(UpdateStockLocationRequest $request, StockLocation $location, UpdateStockLocation $update): StoreLocationResource
    {
        return self::resource($update->handle($location, $request->toData()));
    }

    private static function resource(StockLocation $location): StoreLocationResource
    {
        $location->load(['division', 'district', 'upazila']);
        $location->setAttribute('holds_stock', StockLevel::query()
            ->where('location_id', $location->id)
            ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0)->orWhere('incoming', '!=', 0))
            ->exists());

        return new StoreLocationResource($location);
    }
}
