<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * One courier on Settings → Courier settings & delivery charges. Credentials and the
 * webhook secret are NEVER returned — not to super_admin either: per key only whether it is
 * set and a hint of at most its last four characters (rule 20).
 *
 * `trust` is the provider card state (04-admin-spec A16): `not_connected` while any required
 * credential is missing, `unverified` while no booking has yet succeeded through the driver
 * (`verified = false`, rule 33a — production booking is refused), else `verified`.
 *
 * @property CourierProvider $resource
 */
#[SchemaName('CourierProviderSettings')]
final class CourierProviderSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $saved = $p->credentials ?? [];
        $complete = CourierCredentialFields::complete($p);
        $user = $request->user();

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'name_bn' => $p->name_bn,
            'is_active' => $p->is_active,
            'is_default' => $p->is_default,
            /** @var bool A booking has succeeded through this driver (rule 33a). Set by the booking flow, never by this screen. */
            'verified' => $p->verified,
            /** @var 'verified'|'unverified'|'not_connected' */
            'trust' => ! $complete ? 'not_connected' : ($p->verified ? 'verified' : 'unverified'),
            /** @var bool Booking needs a mapped courier_locations row (Pathao yes, Steadfast no — rule 32). */
            'requires_location_mapping' => $p->requires_location_mapping,
            'supports_bulk' => $p->supports_bulk,
            'supports_webhook' => $p->supports_webhook,
            /** @var list<array{key: string, secret: bool, set: bool, hint: string|null}> The provider's credential keys; `hint` is "••••1234" at most. */
            'credential_fields' => array_map(static fn (string $key): array => [
                'key' => $key,
                'secret' => in_array($key, CourierCredentialFields::SECRET, true),
                'set' => CourierCredentialFields::filled($saved[$key] ?? null),
                'hint' => CourierCredentialFields::hint($saved[$key] ?? null),
            ], CourierCredentialFields::for($p)),
            /** @var bool Every required credential is stored. */
            'credentials_complete' => $complete,
            /** @var string|null */
            'credentials_updated_at' => $p->credentials_updated_at?->toIso8601String(),
            /** @var bool */
            'webhook_secret_set' => $p->webhook_secret !== null && $p->webhook_secret !== '',
            /** @var string|null The URL to register in the courier's merchant panel. */
            'webhook_url' => Route::has('api.v1.webhooks.couriers') ? route('api.v1.webhooks.couriers', ['provider' => $p->code]) : null,
            /** @var array{base_charge_dhaka: string, base_charge_suburb: string, base_charge_outside: string, included_weight_grams: int, per_kg_charge: string, cod_fee_percent: string, return_charge: string} Decimal strings. */
            'rates' => [
                'base_charge_dhaka' => $p->base_charge_dhaka->toDecimal(),
                'base_charge_suburb' => $p->base_charge_suburb->toDecimal(),
                'base_charge_outside' => $p->base_charge_outside->toDecimal(),
                'included_weight_grams' => $p->included_weight_grams,
                'per_kg_charge' => $p->per_kg_charge->toDecimal(),
                'cod_fee_percent' => number_format((float) $p->cod_fee_percent, 2, '.', ''),
                'return_charge' => $p->return_charge->toDecimal(),
            ],
            /** @var string|null */
            'rate_card_updated_at' => $p->rate_card_updated_at?->toIso8601String(),
            /** @var string|null Last balance the provider reported; null = never checked. */
            'balance' => $p->balance?->toDecimal(),
            /** @var string|null */
            'balance_checked_at' => $p->balance_checked_at?->toIso8601String(),
            /** @var array{update_rates: bool, update_credentials: bool} */
            'can' => [
                'update_rates' => (bool) $user?->can('updateRates', $p),
                'update_credentials' => (bool) $user?->can('updateCredentials', $p),
            ],
        ];
    }
}
