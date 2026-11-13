<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Tracking;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * `GET /storefront/tracking/config` — public ids and flags only (TrackingSettings::publicConfig()).
 * Never a secret: no GA4 API secret, no Meta access token, no Meta test-event code.
 *
 * @property array{ga4_measurement_id: ?string, meta_pixel_id: ?string, gtm_container_id: ?string, consent_mode: string, consent_policy_version: ?string, purchase_trigger: string, enabled: array{ga4: bool, meta: bool}} $resource
 */
#[SchemaName('StorefrontTrackingConfig')]
final class TrackingConfigResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            /** @var string|null */
            'ga4_measurement_id' => $c['ga4_measurement_id'],
            /** @var string|null */
            'meta_pixel_id' => $c['meta_pixel_id'],
            /** @var string|null */
            'gtm_container_id' => $c['gtm_container_id'],
            /** @var 'opt_in'|'notice' */
            'consent_mode' => $c['consent_mode'],
            /** @var string|null */
            'consent_policy_version' => $c['consent_policy_version'],
            /** @var 'order_placed'|'order_delivered' */
            'purchase_trigger' => $c['purchase_trigger'],
            /**
             * Whether the browser should load gtag / the Pixel at all.
             *
             * @var array{ga4: bool, meta: bool}
             */
            'enabled' => $c['enabled'],
        ];
    }
}
