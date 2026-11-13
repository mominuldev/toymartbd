<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Model events stay ON here. Laravel's default WithoutModelEvents trait silences the
 * events spatie/laravel-permission uses to refresh its cache, which made a fresh
 * `migrate:fresh --seed` fail at the first role sync.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data — safe and required in every environment.
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(GeographySeeder::class);
        $this->call(CatalogReferenceSeeder::class);
        $this->call(MediaFoldersSeeder::class);
        $this->call(DeliverySeeder::class);
        $this->call(ExpenseCategoriesSeeder::class);

        // A known login is only ever created on a developer's machine or in tests. In any
        // other environment a seeded account with a factory password is a back door.
        if (app()->environment('local', 'testing')) {
            User::query()->firstOrCreate(
                ['email' => 'owner@toymart.test'],
                User::factory()->raw(['name' => 'Local Owner', 'email' => 'owner@toymart.test']),
            )->assignRole(Role::SuperAdmin->value);
            $this->call(CategoryDemoSeeder::class);
            $this->call(BrandDemoSeeder::class);
            $this->call(DemoCatalogSeeder::class);
            $this->call(DemoInventorySeeder::class);
        }
    }
}
