<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Notification\Support\StoreHotline;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StoreInfoResource;

/**
 * The shop's public contact and legal details for the storefront chrome (header hotline,
 * footer legal bar, WhatsApp buttons, error pages). One source: Admin → Settings → Store →
 * Business (`invoice.company.*` + `store.whatsapp`). The storefront caches it under the
 * `store-info` tag; the admin purges that tag on every Business or Invoice-details save.
 */
final class StoreInfoController extends ApiController
{
    public const string CACHE_TAG = 'store-info';

    /** `GET /storefront/store-info`. */
    public function show(CompanyDetails $company): StoreInfoResource
    {
        $saved = $company->saved();
        $text = static fn (string $field): ?string => is_string($saved[$field] ?? null) && trim((string) $saved[$field]) !== '' ? trim((string) $saved[$field]) : null;

        return new StoreInfoResource([
            'name_en' => $text('name_en') ?? CompanyDetails::DEFAULT_NAMES['name_en'],
            'name_bn' => $text('name_bn') ?? CompanyDetails::DEFAULT_NAMES['name_bn'],
            'hotline' => StoreHotline::confirmed(),
            'whatsapp' => $company->whatsapp(),
            'email' => $text('email'),
            'address_en' => $text('address_en'),
            'address_bn' => $text('address_bn'),
            'trade_licence' => $text('trade_licence'),
            'bin' => $text('bin'),
        ]);
    }
}
