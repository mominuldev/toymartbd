<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use RuntimeException;

/**
 * Resolves the demo addresses' district / upazila by their GeographySeeder English names
 * (never by id, so a re-numbered geography table cannot silently move a customer).
 * `null` upazila = the district's city (Dhaka city: district only, rule 31 + checkout).
 */
final class DemoOrderPlaces
{
    /** @var array<string, District> */
    private static array $districts = [];

    /** @var array<string, Upazila> */
    private static array $upazilas = [];

    public static function district(string $name): District
    {
        return self::$districts[$name] ??= District::query()->with('division')->where('name', $name)->first()
            ?? throw new RuntimeException("Demo seed: district {$name} not found — run GeographySeeder first.");
    }

    public static function upazila(string $district, ?string $name): ?Upazila
    {
        if ($name === null) {
            return null;
        }

        return self::$upazilas[$district.'|'.$name] ??= Upazila::query()
            ->where('district_id', self::district($district)->id)->where('name', $name)->first()
            ?? throw new RuntimeException("Demo seed: upazila {$name} ({$district}) not found — run GeographySeeder first.");
    }

    /** Forget the cache (a test's RefreshDatabase gives the next run new rows). */
    public static function flush(): void
    {
        self::$districts = [];
        self::$upazilas = [];
    }
}
