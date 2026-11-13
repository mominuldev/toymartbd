<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Admin → Integrations → Tracking & pixels. Effective values (saved setting, else env).
 * The GA4 API secret and the Meta access token are NEVER returned — not even to
 * super_admin: only whether each is set, where it comes from, and at most its last four
 * characters ({@see \App\Domain\Tracking\Services\TrackingSettings::adminView()}).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('TrackingSettings')]
final class TrackingSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var bool Master switch for every server-side send. */
            'server_side_enabled' => (bool) $r['server_side_enabled'],
            /** @var bool */
            'ga4_enabled' => (bool) $r['ga4_enabled'],
            /** @var string|null */
            'ga4_measurement_id' => $r['ga4_measurement_id'],
            /** @var bool */
            'ga4_api_secret_set' => (bool) $r['ga4_api_secret_set'],
            /** @var string|null "••••1234" at most. */
            'ga4_api_secret_hint' => $r['ga4_api_secret_hint'],
            /** @var 'settings'|'env'|null */
            'ga4_api_secret_source' => $r['ga4_api_secret_source'],
            /** @var bool */
            'meta_enabled' => (bool) $r['meta_enabled'],
            /** @var string|null */
            'meta_pixel_id' => $r['meta_pixel_id'],
            /** @var bool */
            'meta_access_token_set' => (bool) $r['meta_access_token_set'],
            /** @var string|null "••••1234" at most. */
            'meta_access_token_hint' => $r['meta_access_token_hint'],
            /** @var 'settings'|'env'|null */
            'meta_access_token_source' => $r['meta_access_token_source'],
            /** @var string|null While set, Meta receives server events as Test events only. */
            'meta_test_event_code' => $r['meta_test_event_code'],
            /** @var string Graph API version (config only, not editable here). */
            'meta_graph_version' => $r['meta_graph_version'],
            /** @var string|null */
            'gtm_container_id' => $r['gtm_container_id'],
            /** @var 'order_placed'|'order_delivered' Which order event fires `purchase`. */
            'purchase_trigger' => $r['purchase_trigger'],
            /** @var 'opt_in'|'notice' */
            'consent_mode' => $r['consent_mode'],
            /** @var string|null */
            'consent_policy_version' => $r['consent_policy_version'],
            /** @var array<string, 'settings'|'env'> Where each non-secret value comes from. */
            'sources' => $r['sources'],
        ];
    }
}
