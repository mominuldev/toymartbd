<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\Demo\DemoLogin;
use Database\Seeders\Demo\SettingsSnapshot;
use Illuminate\Database\Seeder;

/**
 * Model events stay ON here. Laravel's default WithoutModelEvents trait silences the
 * events spatie/laravel-permission uses to refresh its cache, which made a fresh
 * `migrate:fresh --seed` fail at the first role sync.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * The same seed everywhere. On a deployed host `php artisan toymart:demo:seed` runs it with
     * `demo.live_seed` on, which seeds everything a developer machine gets — demo logins on the
     * typed password, catalog, stock, inbox and the transactional history — once only (refused
     * when any order exists), with every live setting snapshotted to storage/app/backups/ and
     * restored afterwards. The deploy's own fresh-install `db:seed` leaves it off: reference only.
     */
    public function run(): void
    {
        if (! (bool) config('demo.live_seed')) {
            $this->seedAll();

            return;
        }

        if (Order::query()->exists()) {
            $this->command?->info('Orders already exist: the full demo data is seeded only once. Nothing changed.');

            return;
        }

        config(['demo.transactions' => true]);
        $result = SettingsSnapshot::around('demo-seed', function (): void {
            $this->seedAll();
        });
        $this->command?->info('Settings snapshot: storage/app/backups/'.$result['file'].'. Restored '.$result['restored'].' settings to their values before seeding.');
    }

    private function seedAll(): void
    {
        // Reference data — safe and required in every environment.
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SecuritySettingsSeeder::class);
        $this->call(GeographySeeder::class);
        $this->call(CatalogReferenceSeeder::class);
        $this->call(MediaFoldersSeeder::class);
        $this->call(DeliverySeeder::class);
        $this->call(ExpenseCategoriesSeeder::class);
        if (! DemoLogin::enabled()) {
            $this->call(CmsHomeBlocksSeeder::class);
        }
        $this->call(StaticPagesSeeder::class);
        $this->call(StaticPageSectionsSeeder::class);

        // A known login is only ever created on a developer's machine or in tests, or on a host
        // through `toymart:demo:seed` with the password typed at its hidden prompt (DemoLogin).
        if (DemoLogin::enabled()) {
            // Local sign-in: mominulfed@gmail.com / password. Change either at /admin/account.
            User::query()->firstOrCreate(
                ['email' => 'mominulfed@gmail.com'],
                DemoLogin::attributes('Mominul Islam', 'mominulfed@gmail.com'),
            )->assignRole(Role::SuperAdmin->value);
            $this->call(CategoryDemoSeeder::class);
            $this->call(BrandDemoSeeder::class);
            $this->call(DemoCatalogSeeder::class);
            $this->call(DemoInventorySeeder::class);
            // After the demo catalogue: the home's category chips and gift picks name its rows.
            $this->call(CmsHomeBlocksSeeder::class);
            // Demo inbox conversations (local/testing only; the admin E2E thread tests use them).
            $this->call(DemoInboxSeeder::class);

            if ((bool) config('demo.transactions')) {
                $this->call(DemoDataSeeder::class);
            }
        }
    }
}
