<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Delivery reference data (P4-CRT-03) — every environment: the courier provider rows
 * with their rate cards, and the `delivery.*` settings.
 *
 * Insert-only: an existing provider or setting is never overwritten, so an owner's
 * edit survives a re-seed.
 *
 * PLACEHOLDER VALUES (UI-UX-DESIGN-SPEC §10, owner decision pending): the customer
 * charges are the design's ৳70 / ৳100 / ৳130 (03-storefront-spec A12.4). The weight
 * steps follow each provider's published shape (docs/_research/agent4 §3.1):
 * Steadfast +৳20 per kg above 1 kg; Pathao +৳15 per kg above 2 kg (Pathao's outside-Dhaka
 * +৳25 is not modelled — one per-kg rate per provider). No merchant account exists, so
 * both are `verified = false` (rule 33a). Steadfast is the default, as the design names
 * it on the PDP ("Courier: Steadfast").
 */
class DeliverySeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    public const array PROVIDERS = [
        [
            'code' => 'steadfast', 'name' => 'Steadfast', 'name_bn' => 'স্টেডফাস্ট',
            'is_active' => true, 'is_default' => true, 'verified' => false, 'requires_location_mapping' => false,
            'supports_bulk' => true, 'supports_webhook' => true,
            'base_charge_dhaka' => '70.00', 'base_charge_suburb' => '100.00', 'base_charge_outside' => '130.00',
            'included_weight_grams' => 1000, 'per_kg_charge' => '20.00', 'cod_fee_percent' => '1.00', 'return_charge' => '0.00',
            'position' => 0,
        ],
        [
            'code' => 'pathao', 'name' => 'Pathao', 'name_bn' => 'পাঠাও',
            'is_active' => false, 'is_default' => false, 'verified' => false, 'requires_location_mapping' => true,
            'supports_bulk' => true, 'supports_webhook' => true,
            'base_charge_dhaka' => '70.00', 'base_charge_suburb' => '100.00', 'base_charge_outside' => '130.00',
            'included_weight_grams' => 2000, 'per_kg_charge' => '15.00', 'cod_fee_percent' => '1.00', 'return_charge' => '0.00',
            'position' => 1,
        ],
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as $provider) {
            CourierProvider::query()->firstOrCreate(['code' => $provider['code']], $provider);
        }

        foreach (DeliverySettings::DEFAULTS as $key => $value) {
            if (! Setting::query()->whereKey(DeliverySettings::PREFIX.$key)->exists()) {
                Setting::put(DeliverySettings::PREFIX.$key, $value, SettingGroup::Delivery);
            }
        }
    }
}
