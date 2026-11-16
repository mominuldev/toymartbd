<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Services\SiteAssets;
use App\Domain\Content\Services\SiteBranding;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Notification\Support\StoreHotline;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StoreInfoResource;

/**
 * The shop's public contact and legal details for the storefront chrome (header hotline,
 * footer legal bar, WhatsApp and Messenger chat links, error pages). One source: Admin → Settings → Store →
 * Business (`invoice.company.*` + `store.whatsapp` + `store.messenger`), plus `site` — the storefront's
 * name, tagline, description, logos, icons and share image from Store → Site & branding (`store.*`).
 * The storefront caches it under the `store-info` tag; the admin purges that tag on every Business,
 * Invoice-details or Site & branding save.
 */
final class StoreInfoController extends ApiController
{
    public const string CACHE_TAG = 'store-info';

    /** `GET /storefront/store-info`. */
    public function show(CompanyDetails $company, SiteBranding $branding): StoreInfoResource
    {
        $saved = $company->saved();
        $text = static fn (string $field): ?string => is_string($saved[$field] ?? null) && trim((string) $saved[$field]) !== '' ? trim((string) $saved[$field]) : null;

        return new StoreInfoResource([
            'name_en' => $text('name_en') ?? CompanyDetails::DEFAULT_NAMES['name_en'],
            'name_bn' => $text('name_bn') ?? CompanyDetails::DEFAULT_NAMES['name_bn'],
            'hotline' => StoreHotline::confirmed(),
            'whatsapp' => $company->whatsapp(),
            'messenger' => $company->messenger(),
            'email' => $text('email'),
            'address_en' => $text('address_en'),
            'address_bn' => $text('address_bn'),
            'trade_licence' => $text('trade_licence'),
            'bin' => $text('bin'),
            'site' => self::site($branding, $text('name_en'), $text('name_bn')),
        ]);
    }

    /**
     * Site & branding for the storefront chrome and <head>: names fall back to the business name,
     * then the brand; an unset text or image is null (the storefront keeps its own default).
     *
     * @return array<string, mixed>
     */
    private static function site(SiteBranding $branding, ?string $businessEn, ?string $businessBn): array
    {
        $values = $branding->saved();
        $media = $branding->media($values);
        $text = static fn (string $f): ?string => is_string($values[$f] ?? null) ? $values[$f] : null;
        $image = static fn (?Media $m, int $width): ?array => $m === null || $m->url($width) === null ? null : ['url' => $m->url($width), 'width' => $m->width, 'height' => $m->height];
        $icon = $media['icon_media_id'];
        $social = $media['social_image_media_id'];

        return [
            'name_en' => SiteBranding::pickName($text('name'), $businessEn, CompanyDetails::DEFAULT_NAMES['name_en']),
            'name_bn' => SiteBranding::pickName($text('name_bn'), $businessBn, CompanyDetails::DEFAULT_NAMES['name_bn']),
            'tagline_en' => $text('tagline'),
            'tagline_bn' => $text('tagline_bn'),
            'description_en' => $text('description'),
            'description_bn' => $text('description_bn'),
            'logo' => $image($media['logo_media_id'], 480),
            'logo_dark' => $image($media['logo_dark_media_id'], 480),
            'icons' => $icon === null ? [] : array_map(
                static fn (int $s): array => ['size' => $s, 'url' => route('site-assets', ['media' => $icon->id, 'name' => "icon-{$s}.png"])],
                SiteBranding::ICON_SIZES,
            ),
            'social_image' => $social === null ? null : [
                'url' => route('site-assets', ['media' => $social->id, 'name' => SiteAssets::SHARE]),
                'width' => SiteBranding::SOCIAL_SIZE[0],
                'height' => SiteBranding::SOCIAL_SIZE[1],
            ],
        ];
    }
}
