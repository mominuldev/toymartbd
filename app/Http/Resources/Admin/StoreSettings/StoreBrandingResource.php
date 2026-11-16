<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Site & branding (Figma B · 38 Premium `390:69035`). `values` are the saved
 * values (null when unset); `logo_media_id` is the same value `GET /settings/invoice` returns.
 * `media` describes each chosen image for the upload tiles and the live preview; `url` is a
 * ~320 px rendition, null until the renditions are ready.
 *
 * @property array{values: array<string, string|int|null>, media: array<string, array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null>, fallbacks: array{site_name_en: string, site_name_bn: string}, storefront_host: string|null, limits: array{site_name: int, tagline: int, description: int}} $resource
 */
#[SchemaName('StoreBranding')]
final class StoreBrandingResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource['values'];
        $m = $this->resource['media'];

        return [
            /**
             * Saved values, null when unset.
             *
             * @var array{site_name_en: string|null, site_name_bn: string|null, tagline_en: string|null, tagline_bn: string|null, description_en: string|null, description_bn: string|null, logo_media_id: int|null, logo_dark_media_id: int|null, icon_media_id: int|null, share_image_media_id: int|null}
             */
            'values' => [
                'site_name_en' => $v['site_name_en'] ?? null,
                'site_name_bn' => $v['site_name_bn'] ?? null,
                'tagline_en' => $v['tagline_en'] ?? null,
                'tagline_bn' => $v['tagline_bn'] ?? null,
                'description_en' => $v['description_en'] ?? null,
                'description_bn' => $v['description_bn'] ?? null,
                'logo_media_id' => $v['logo_media_id'] ?? null,
                'logo_dark_media_id' => $v['logo_dark_media_id'] ?? null,
                'icon_media_id' => $v['icon_media_id'] ?? null,
                'share_image_media_id' => $v['share_image_media_id'] ?? null,
            ],
            /**
             * The chosen images (null when none, or the media row is gone). `url` is a ~320 px rendition.
             *
             * @var array{logo: array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null, logo_dark: array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null, icon: array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null, share_image: array{id: int, filename: string, width: int|null, height: int|null, url: string|null}|null}
             */
            'media' => [
                'logo' => $m['logo'] ?? null,
                'logo_dark' => $m['logo_dark'] ?? null,
                'icon' => $m['icon'] ?? null,
                'share_image' => $m['share_image'] ?? null,
            ],
            /**
             * What the storefront shows while a site name is blank: the business name (Store
             * settings › Business), or the brand name while that is unset too.
             *
             * @var array{site_name_en: string, site_name_bn: string}
             */
            'fallbacks' => $this->resource['fallbacks'],
            /**
             * The storefront's host for the preview's address bar, e.g. `toymartbd.com`.
             *
             * @var string|null
             */
            'storefront_host' => $this->resource['storefront_host'],
            /**
             * Character limits for the counters.
             *
             * @var array{site_name: int, tagline: int, description: int}
             */
            'limits' => $this->resource['limits'],
        ];
    }
}
