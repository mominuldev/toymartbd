<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The whole local demo dataset, end to end: `db:seed` with DEMO_SEED_TRANSACTIONS on must
 * finish without an external call and leave both build invariants intact — stock movements
 * sum to stock levels, and order financials equal the ledger.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('is off by default outside local, so the Pest suite and the admin E2E stay exact', function (): void {
    expect(config('demo.transactions'))->toBeFalse();

    $this->seed(DatabaseSeeder::class);

    expect(DB::table('orders')->count())->toBe(0);
});

it('seeds every module through DatabaseSeeder with both invariants intact', function (): void {
    config(['demo.transactions' => true]);

    $this->seed(DatabaseSeeder::class);

    foreach (['customers', 'addresses', 'orders', 'order_items', 'payments', 'shipments', 'order_returns', 'refunds',
        'courier_settlements', 'suppliers', 'purchases', 'expenses', 'coupons', 'promotions', 'reviews',
        'blog_posts', 'banners', 'menus', 'testimonials', 'redirects', 'financial_transactions', 'stock_movements'] as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, "{$table} is empty");
    }

    expect(Artisan::call('toymart:inventory:reconcile'))->toBe(0, Artisan::output())
        ->and(Artisan::call('toymart:finance:verify'))->toBe(0, Artisan::output());

    // Idempotent: a second run adds nothing.
    $before = collect(['orders', 'customers', 'purchases', 'expenses', 'reviews', 'blog_posts', 'stock_movements', 'financial_transactions'])
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()]);
    $this->seed(DemoDataSeeder::class);
    $before->each(fn (int $count, string $t) => expect(DB::table($t)->count())->toBe($count, "{$t} grew on re-run"));
})->group('slow');
