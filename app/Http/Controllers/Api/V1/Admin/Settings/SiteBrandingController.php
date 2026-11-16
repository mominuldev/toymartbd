<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Content\Actions\UpdateSiteBranding;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Services\SiteBranding;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\StoreInfoController;
use App\Http\Requests\Admin\StoreSettings\UpdateSiteBrandingRequest;
use App\Http\Resources\Admin\StoreSettings\SiteBrandingResource;
use App\Models\Enums\Permission;

/**
 * Store settings › Site & branding (Figma B · 38 Store settings · Premium): the storefront's name,
 * tagline and description in both locales, its logos, the store icon its favicons are made from
 * and the share image. Read with settings.view, save with settings.update — the same rights as
 * Business, its sibling section. A separate endpoint rather than more fields on
 * `/settings/business`: Business is a second view of the invoice identity (`invoice.company.*`),
 * and the storefront's look is not printed on invoices.
 */
final class SiteBrandingController extends ApiController
{
    /** `GET /settings/site` */
    public function show(SiteBranding $branding, CompanyDetails $company): SiteBrandingResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($branding, $company);
    }

    /** `PUT /settings/site` — the storefront shows the change once its `store-info` cache is purged. */
    public function update(UpdateSiteBrandingRequest $request, UpdateSiteBranding $update, SiteBranding $branding, CompanyDetails $company): SiteBrandingResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        if ($update->handle($request->toData(), is_int($userId) ? $userId : null)) {
            // Header logo, <title>, favicons and share cards all read store-info. Queued, after commit.
            RevalidateStorefrontTags::dispatch([StoreInfoController::CACHE_TAG]);
        }

        return self::view($branding, $company);
    }

    private static function view(SiteBranding $branding, CompanyDetails $company): SiteBrandingResource
    {
        $values = $branding->saved();
        $business = $company->saved();
        $name = static fn (mixed $v): ?string => is_string($v) ? $v : null;

        return new SiteBrandingResource([
            'values' => $values,
            'media' => $branding->media($values),
            'fallback_names' => [
                'name' => SiteBranding::pickName(null, $name($business['name_en'] ?? null), CompanyDetails::DEFAULT_NAMES['name_en']),
                'name_bn' => SiteBranding::pickName(null, $name($business['name_bn'] ?? null), CompanyDetails::DEFAULT_NAMES['name_bn']),
            ],
        ]);
    }
}
