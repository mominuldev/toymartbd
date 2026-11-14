<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Ordering\Services\CheckoutRules;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\CheckoutSettingsResource;
use App\Models\Enums\Permission;

/**
 * Store settings › Checkout & delivery rules (Figma B · 38, docs/api/checkout-settings.md).
 * Read-only with settings.view: what checkout really does. The free-delivery threshold is
 * written through `PUT /settings/delivery` and the zone charges through the default
 * courier's rate card — one source of truth each, never a second copy here.
 */
final class CheckoutSettingsController extends ApiController
{
    /** `GET /settings/checkout` */
    public function show(CheckoutRules $rules): CheckoutSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return new CheckoutSettingsResource($rules->snapshot());
    }
}
