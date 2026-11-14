<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One outside service on the Integrations hub (Figma B · 37). Every field is derived from
 * stored state by {@see \App\Http\Integrations\IntegrationHub}; no credential, hint or
 * secret is ever part of it — only note codes such as `no_credentials`.
 *
 * @property array{key: string, name: string, category: string, status: string, notes: list<string>, attention: ?string, bookings_count: ?int, enabled: ?bool, courier_provider_id: ?int, can_toggle: bool, settings_path: ?string} $resource
 */
#[SchemaName('Integration')]
final class IntegrationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var string Stable service key: pathao, steadfast, redx, cod, sslcommerz, bkash, nagad, sms, whatsapp, messenger, meta_capi, ga4, gtm, search_console. */
            'key' => $r['key'],
            /** @var string English name; the admin translates by `key` where the name is not a brand. */
            'name' => $r['name'],
            /** @var 'couriers'|'payments'|'messaging'|'marketing' */
            'category' => $r['category'],
            /** @var 'connected_live'|'test_mode'|'needs_attention'|'not_connected'|'not_available' `not_available` = no driver in the codebase yet. */
            'status' => $r['status'],
            /** @var list<string> Factual note codes for the detail line, e.g. `no_credentials`, `sandbox`, `unverified_booking_blocked`, `bookings`, `browser_only`. */
            'notes' => $r['notes'],
            /** @var string|null Why it needs attention (a code), when `status` is `needs_attention`. */
            'attention' => $r['attention'],
            /** @var int|null Shipments booked through this courier (with a consignment id). */
            'bookings_count' => $r['bookings_count'],
            /** @var bool|null Switched on/off where the service has such a switch; null when it has none. */
            'enabled' => $r['enabled'],
            /** @var int|null The courier row, for `PATCH /settings/couriers/{id}` (the only on/off this screen offers). */
            'courier_provider_id' => $r['courier_provider_id'],
            /** @var bool This user may switch it on/off here (couriers only, shipments.manage_rates). */
            'can_toggle' => $r['can_toggle'],
            /** @var string|null The admin screen that configures it; null when none exists. */
            'settings_path' => $r['settings_path'],
        ];
    }
}
