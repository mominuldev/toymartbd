<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierRegistry;

/**
 * The courier fixture harness (rule 33a: no merchant account exists, so the whole
 * fulfilment flow runs in CI against NullCourierGateway).
 *
 *     $courier = CourierFixtures::install('steadfast');          // provider row + fake driver
 *     $courier->willBook(BookingResult::refused('Invalid phone')); // script the next answer
 *     ...
 *     expect($courier->booked)->toHaveCount(1);                   // inspect what was sent
 *
 * `install()` creates (or updates) the `courier_providers` row — active, default, with a
 * placeholder rate card — and registers a fresh NullCourierGateway for that code in the
 * container's CourierRegistry singleton, so queued jobs (sync in tests) use the same
 * instance.
 */
final class CourierFixtures
{
    /** @param  array<string, mixed>  $attributes  courier_providers overrides */
    public static function provider(string $code = 'steadfast', array $attributes = []): CourierProvider
    {
        $provider = CourierProvider::query()->firstOrNew(['code' => $code]);
        $provider->forceFill($attributes + [
            'name' => ucfirst($code),
            'name_bn' => $code === 'pathao' ? 'পাঠাও' : 'স্টেডফাস্ট',
            'is_active' => true,
            'is_default' => true,
            'verified' => false,
            'requires_location_mapping' => false,
            'supports_bulk' => true,
            'supports_webhook' => true,
            'base_charge_dhaka' => '70.00',
            'base_charge_suburb' => '100.00',
            'base_charge_outside' => '130.00',
            'included_weight_grams' => 1000,
            'per_kg_charge' => '20.00',
            'cod_fee_percent' => '1.00',
            'return_charge' => '50.00',
            'position' => 0,
        ])->save();

        if (($attributes['is_default'] ?? true) === true) {
            CourierProvider::query()->whereKeyNot($provider->id)->update(['is_default' => false]);
        }

        return $provider->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    public static function install(
        string $code = 'steadfast',
        array $attributes = [],
        bool $requiresLocationMapping = false,
        bool $supportsBulk = true,
        string $charge = '70.00',
    ): NullCourierGateway {
        self::provider($code, $attributes + ['requires_location_mapping' => $requiresLocationMapping, 'supports_bulk' => $supportsBulk]);

        $driver = new NullCourierGateway($code, $requiresLocationMapping, $supportsBulk, 'null-secret', $charge);
        app(CourierRegistry::class)->register($code, $driver);

        return $driver;
    }
}
