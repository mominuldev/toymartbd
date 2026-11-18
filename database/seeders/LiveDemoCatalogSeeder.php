<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The demo catalog — categories, brands, products and opening stock — for a deployed host
 * (owner request 2026-11-18: fill the live shop the way uptms-ad's deploy fills its site).
 * Run by hand through the deploy workflow's `seed_demo_catalog` input, never on a push.
 *
 * First time only: once any product exists (seeded or the owner's own, archived or not) it
 * does nothing, so it can never mix demo items into a real catalog.
 *
 * The live settings are kept: every `settings` row is snapshotted before seeding — written
 * to the backups disk as a JSON file — and restored exactly afterwards, so a seeder that
 * writes a setting (DemoInventorySeeder's branch mode) never changes what the owner chose.
 * Settings that did not exist before stay as the seeders wrote them.
 *
 * No demo orders, customers, finance or logins: those stay local/testing only.
 */
class LiveDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (Product::withTrashed()->exists()) {
            $this->command?->info('Products already exist: the demo catalog is seeded only on an empty shop. Nothing changed.');

            return;
        }

        $snapshot = DB::table('settings')->get()->map(fn (object $row): array => (array) $row)->all();
        $file = 'settings-before-demo-catalog-'.now()->format('YmdHis').'.json';
        Storage::disk('backups')->put($file, (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->command?->info('Settings snapshot written to storage/app/backups/'.$file.'.');

        try {
            $this->call([
                CatalogReferenceSeeder::class,
                CategoryDemoSeeder::class,
                BrandDemoSeeder::class,
                DemoCatalogSeeder::class,
                DemoInventorySeeder::class,
            ]);
        } finally {
            foreach ($snapshot as $row) {
                $key = $row['key'];
                unset($row['key']);
                DB::table('settings')->updateOrInsert(['key' => $key], $row);
            }
            $this->command?->info('Restored '.count($snapshot).' settings to their values before seeding.');
        }
    }
}
