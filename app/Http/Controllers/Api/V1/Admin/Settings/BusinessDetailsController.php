<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Actions\UpdateBusinessDetails;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\StoreInfoController;
use App\Http\Requests\Admin\StoreSettings\UpdateBusinessDetailsRequest;
use App\Http\Resources\Admin\StoreSettings\BusinessDetailsResource;
use App\Models\Enums\Permission;

/**
 * Store settings › Business (Figma B · 38 241:50827): store name (EN/BN), trade licence,
 * BIN, support hotline and WhatsApp. Read with settings.view, save with settings.update.
 *
 * Single source of truth: name, trade licence, BIN and hotline are the `invoice.company.*`
 * settings Invoice details (`/settings/invoice`) reads and writes — this endpoint is a
 * second view of those rows, not a copy.
 */
final class BusinessDetailsController extends ApiController
{
    /** `GET /settings/business` */
    public function show(CompanyDetails $company): BusinessDetailsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($company);
    }

    /** `PUT /settings/business` — affects invoices issued from now on; issued ones keep their snapshot. */
    public function update(UpdateBusinessDetailsRequest $request, UpdateBusinessDetails $update, CompanyDetails $company): BusinessDetailsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);
        // The storefront chrome shows these values (hotline, legal bar): purge its cache. Queued, after commit.
        RevalidateStorefrontTags::dispatch([StoreInfoController::CACHE_TAG]);

        return self::view($company);
    }

    private static function view(CompanyDetails $company): BusinessDetailsResource
    {
        $saved = $company->saved();
        $values = [];
        foreach (CompanyDetails::BUSINESS_FIELDS as $field) {
            $values[$field] = is_string($saved[$field]) ? $saved[$field] : null;
        }
        $hotline = config('notifications.hotline');

        return new BusinessDetailsResource([
            'values' => $values,
            'whatsapp' => $company->whatsapp(),
            'placeholders' => array_values(array_filter(
                CompanyDetails::BUSINESS_FIELDS,
                static fn (string $f): bool => $values[$f] === null, // every Business field is required on invoices
            )),
            'default_names' => CompanyDetails::DEFAULT_NAMES,
            'hotline_default' => is_string($hotline) && $hotline !== '' ? $hotline : null,
        ]);
    }
}
