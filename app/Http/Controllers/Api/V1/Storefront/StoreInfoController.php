<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Domain\Notification\Support\StoreHotline;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StoreInfoResource;

/**
 * The shop's public contact and legal details for the storefront chrome (header hotline,
 * footer legal bar, WhatsApp and Messenger chat links, error pages). One source: Admin → Settings → Store →
 * Business (`invoice.company.*` + `store.whatsapp` + `store.messenger`), plus Site & branding
 * (`store.branding.*`: site name, tagline, description, logos, share image, favicons). The storefront
 * caches it under the `store-info` tag; the admin purges that tag on every Business, Invoice-details or
 * Site & branding save, and again once the favicons are rendered.
 */
final class StoreInfoController extends ApiController
{
    public const string CACHE_TAG = 'store-info';

    /** The header logo rendition (~480 px: a 40 px-high logo at 2× with room to spare). */
    public const int LOGO_WIDTH = 480;

    /** The Open Graph image rendition (1200 × 630). */
    public const int SHARE_WIDTH = 1200;

    /** `GET /storefront/store-info`. */
    public function show(CompanyDetails $company, StoreBranding $branding): StoreInfoResource
    {
        $saved = $company->saved();
        $brand = $branding->saved();
        $brandText = static fn (string $field): ?string => is_string($brand[$field] ?? null) ? trim((string) $brand[$field]) : null;
        $image = static fn (string $field): ?Media => StoreBranding::readyImage(is_int($brand[$field] ?? null) ? $brand[$field] : null);
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
            'site_name_en' => $brandText('site_name_en'),
            'site_name_bn' => $brandText('site_name_bn'),
            'tagline_en' => $brandText('tagline_en'),
            'tagline_bn' => $brandText('tagline_bn'),
            'description_en' => $brandText('description_en'),
            'description_bn' => $brandText('description_bn'),
            'logo' => StoreBranding::rendition($image('logo_media_id'), self::LOGO_WIDTH),
            'logo_dark' => StoreBranding::rendition($image('logo_dark_media_id'), self::LOGO_WIDTH),
            'share_image' => StoreBranding::rendition($image('share_image_media_id'), self::SHARE_WIDTH),
            'icons' => $branding->iconUrls(),
        ]);
    }
}
