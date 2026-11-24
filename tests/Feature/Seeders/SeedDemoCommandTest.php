<?php

declare(strict_types=1);

use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * toymart:demo:seed — the full local demo data set on a deployed host, over SSH: once only,
 * demo logins on the typed password (never the factory one), live settings restored.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Storage::fake('backups');
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'demo.history_days' => 7]);
});

it('seeds everything with the typed password and keeps the live settings', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    Setting::put('store.name', 'Smart Junior Live', SettingGroup::Store);
    Setting::put('payments.secret', 'k-123', SettingGroup::Payment, encrypted: true);

    $this->artisan('toymart:demo:seed', ['--no-images' => true])
        ->expectsConfirmation('Seed the full demo data set here?', 'yes')
        ->expectsQuestion('Password for every demo login (min 12 characters)', 'a-long-live-pass')
        ->expectsQuestion('Confirm password', 'a-long-live-pass')
        ->assertSuccessful();

    foreach (['customers', 'orders', 'payments', 'shipments', 'purchases', 'expenses', 'reviews', 'products', 'stock_movements'] as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, "{$table} is empty");
    }
    expect(Artisan::call('toymart:inventory:reconcile'))->toBe(0, Artisan::output())
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0, Artisan::output());

    $owner = User::query()->where('email', 'mominulfed@gmail.com')->firstOrFail();
    $staff = User::query()->where('email', 'manager@toymart.test')->firstOrFail();
    expect(Hash::check('a-long-live-pass', $owner->password))->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeFalse()
        ->and(Hash::check('a-long-live-pass', $staff->password))->toBeTrue()
        ->and(Setting::get('store.name'))->toBe('Smart Junior Live')
        ->and(Setting::get('payments.secret'))->toBe('k-123')
        ->and(Storage::disk('backups')->files())->toHaveCount(1);
})->group('slow');

it('changes nothing once orders exist', function (): void {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['demo.live_seed' => true]);
    $this->seed(Database\Seeders\DatabaseSeeder::class);
    $orders = DB::table('orders')->count();
    expect($orders)->toBeGreaterThan(0);
    config(['demo.live_seed' => false]);

    $this->artisan('toymart:demo:seed', ['--no-images' => true])
        ->expectsConfirmation('Seed the full demo data set here?', 'yes')
        ->expectsQuestion('Password for every demo login (min 12 characters)', 'a-long-live-pass')
        ->expectsQuestion('Confirm password', 'a-long-live-pass')
        ->expectsOutputToContain('Orders already exist')
        ->assertSuccessful();

    expect(DB::table('orders')->count())->toBe($orders);
})->group('slow');

it('refuses a short or mismatched password and seeds nothing', function (): void {
    $this->artisan('toymart:demo:seed')
        ->expectsConfirmation('Seed the full demo data set here?', 'yes')
        ->expectsQuestion('Password for every demo login (min 12 characters)', 'short')
        ->assertFailed();

    expect(DB::table('orders')->count())->toBe(0)->and(DB::table('users')->count())->toBe(0);
});
