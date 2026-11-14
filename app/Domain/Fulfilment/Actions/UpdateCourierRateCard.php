<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Events\DeliveryReferenceDataChanged;
use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Support\Facades\DB;

/**
 * Save a courier's rate card (`PUT /settings/couriers/{provider}/rates`). These columns are
 * what {@see \App\Domain\Fulfilment\Services\DeliveryChargeResolver} reads: the default
 * provider's base charge per zone, its included weight and per-started-kg step (Steadfast
 * 1 kg, Pathao 2 kg — rule 32a), so the storefront quote changes with the next request.
 * Shape validation (non-negative DECIMAL(14,2), whole kilograms) is the FormRequest's.
 */
final readonly class UpdateCourierRateCard
{
    public const array FIELDS = [
        'base_charge_dhaka', 'base_charge_suburb', 'base_charge_outside',
        'included_weight_grams', 'per_kg_charge', 'cod_fee_percent', 'return_charge',
    ];

    /**
     * What DeliveryChargeResolver prices storefront quotes and the published rate table with.
     * COD fee and return charge are what the courier bills us, never shown to a customer.
     */
    public const array STOREFRONT_FIELDS = [
        'base_charge_dhaka', 'base_charge_suburb', 'base_charge_outside', 'included_weight_grams', 'per_kg_charge',
    ];

    /** @param array<string, mixed> $rates */
    public function handle(CourierProvider $provider, array $rates): CourierProvider
    {
        return DB::transaction(static function () use ($provider, $rates): CourierProvider {
            /** @var CourierProvider $locked */
            $locked = CourierProvider::query()->lockForUpdate()->findOrFail($provider->id);
            $locked->fill(array_intersect_key($rates, array_flip(self::FIELDS)));
            if ($locked->isDirty()) {
                $storefrontChanged = $locked->isDirty(self::STOREFRONT_FIELDS);
                $locked->rate_card_updated_at = now();
                $locked->save();
                // Storefront rate table (`delivery` tag), held until commit.
                if ($storefrontChanged) {
                    DeliveryReferenceDataChanged::dispatch('rate_card');
                }
            }

            return $locked->refresh();
        });
    }
}
