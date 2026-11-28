<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\IssueStorefrontToken;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\Demo\DemoLogin;
use Database\Seeders\Demo\SettingsSnapshot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            // Demo inbox conversations: off unless DEMO_SEED_INBOX=true (the admin E2E thread tests use them).
            if ((bool) config('demo.inbox')) {
                $this->call(DemoInboxSeeder::class);
            }

            if ((bool) config('demo.transactions')) {
                $this->call(DemoDataSeeder::class);
            }
        }

        $this->localStorefrontToken();
    }

    /**
     * The local storefront's API token, the same after every `migrate:fresh --seed`: a fresh
     * database has no tokens, so the storefront (../toymartbd-storefront/.env.local) would get a
     * 401 on every call and render an empty home. Its STOREFRONT_API_TOKEN is this value.
     * Developer machine only — never on a host, where `toymart:storefront-token` mints a secret one.
     */
    public const string LOCAL_STOREFRONT_TOKEN = 'local-dev-storefront-token';

    private function localStorefrontToken(): void
    {
        if (! app()->environment('local') || (bool) config('demo.live_seed')) {
            return;
        }

        $account = User::query()->firstOrCreate(
            ['email' => IssueStorefrontToken::SERVICE_EMAIL],
            ['name' => 'Storefront server (service account)', 'password' => Hash::make(Str::random(64)), 'is_active' => false],
        );
        $hash = hash('sha256', self::LOCAL_STOREFRONT_TOKEN);
        if (! $account->tokens()->where('token', $hash)->exists()) {
            $account->tokens()->create(['name' => IssueStorefrontToken::TOKEN_NAME, 'token' => $hash, 'abilities' => IssueStorefrontToken::ABILITIES]);
        }
    }
}
