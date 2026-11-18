<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Actions\UpdateStoreBranding;
use App\Domain\Invoicing\Jobs\RenderStoreFavicons;
use App\Domain\Invoicing\Seo\HomeSeoSubjects;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Domain\Seo\Services\SiteUrl;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\StoreInfoController;
use App\Http\Requests\Admin\StoreSettings\UpdateStoreBrandingRequest;
use App\Http\Resources\Admin\StoreSettings\StoreBrandingResource;
use App\Models\Enums\Permission;
use Illuminate\Support\Facades\Bus;

/**
 * Store settings › Site & branding (Figma B · 38 Premium `390:69035`): the storefront's name,
 * tagline and description (EN/BN), logo, dark logo, store icon and share image. Read with
 * settings.view, save with settings.update. The logo is the invoice logo (one row).
 */
final class StoreBrandingController extends ApiController
{
    /** `GET /settings/branding` */
    public function show(StoreBranding $branding, CompanyDetails $company): StoreBrandingResource
    {
        $this->authorize(Permission::SettingsView->value);

        return self::view($branding, $company);
    }

    /** `PUT /settings/branding` — the whole section; null or blank clears a field. */
    public function update(UpdateStoreBrandingRequest $request, UpdateStoreBranding $update, StoreBranding $branding, CompanyDetails $company): StoreBrandingResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $data = $request->toData();
        $iconChanged = $update->handle($data, is_int($userId) ? $userId : null);

        // The storefront chrome and metadata show these values: purge its cache. Queued, after commit.
        // `home:home` is the homepage's SEO document, whose defaults are these texts (HomeSeoSubjects).
        RevalidateStorefrontTags::dispatch([StoreInfoController::CACHE_TAG, HomeSeoSubjects::TYPE.':'.HomeSeoSubjects::SLUG]);
        $icon = $data->media['icon_media_id'] ?? null;
        if ($iconChanged && $icon !== null) {
            // Render the PNG favicons, then purge again so the storefront picks them up.
            Bus::chain([new RenderStoreFavicons($icon), new RevalidateStorefrontTags([StoreInfoController::CACHE_TAG])])->dispatch();
        }

        return self::view($branding, $company);
    }

    private static function view(StoreBranding $branding, CompanyDetails $company): StoreBrandingResource
    {
        $values = $branding->saved();
        $names = $company->saved();
        $host = parse_url(SiteUrl::base(), PHP_URL_HOST);

        return new StoreBrandingResource([
            'values' => $values,
            'media' => [
                'logo' => self::media($values['logo_media_id']),
                'logo_dark' => self::media($values['logo_dark_media_id']),
                'icon' => self::media($values['icon_media_id']),
                'share_image' => self::media($values['share_image_media_id']),
            ],
            'fallbacks' => [
                'site_name_en' => is_string($names['name_en']) ? $names['name_en'] : CompanyDetails::DEFAULT_NAMES['name_en'],
                'site_name_bn' => is_string($names['name_bn']) ? $names['name_bn'] : CompanyDetails::DEFAULT_NAMES['name_bn'],
            ],
            'storefront_host' => is_string($host) && $host !== '' ? $host : null,
            'limits' => StoreBranding::LIMITS,
        ]);
    }

    /** @return array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null */
    private static function media(string|int|null $id): ?array
    {
        $media = is_int($id) ? Media::query()->find($id) : null;
        if (! $media instanceof Media) {
            return null;
        }

        return ['id' => $media->id, 'filename' => $media->filename, 'width' => $media->width, 'height' => $media->height, 'url' => $media->url(320)];
    }
}
