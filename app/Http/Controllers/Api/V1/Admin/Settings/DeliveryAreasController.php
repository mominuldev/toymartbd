<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Fulfilment\Actions\UpdateDeliveryAreaSettings;
use App\Domain\Fulfilment\DTOs\DeliveryAreaSettings;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\StoreSettings\UpdateDeliveryAreasRequest;
use App\Http\Resources\Admin\StoreSettings\DeliveryAreasResource;
use App\Models\Enums\Permission;

/**
 * Store settings › Delivery areas & timing: order cut-off, ETA days per zone, metro/suburb
 * districts, not-serviced upazilas and the COD advance rule — the `delivery.*` settings
 * DeliveryChargeResolver reads (docs/api/delivery.md). Read with settings.view; save with
 * settings.update + shipments.manage_rates (they change what every quote charges and promises).
 */
final class DeliveryAreasController extends ApiController
{
    /** `GET /settings/delivery-areas` */
    public function show(DeliverySettings $settings): DeliveryAreasResource
    {
        $this->authorize(Permission::SettingsView->value);

        return new DeliveryAreasResource(DeliveryAreaSettings::fromSettings($settings));
    }

    /** `PUT /settings/delivery-areas` — the whole document; an unchanged save writes nothing and purges nothing. */
    public function update(UpdateDeliveryAreasRequest $request, UpdateDeliveryAreaSettings $update, DeliverySettings $settings): DeliveryAreasResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);
        $settings->refresh();

        return new DeliveryAreasResource(DeliveryAreaSettings::fromSettings($settings));
    }
}
