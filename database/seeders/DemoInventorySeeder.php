<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\RecordOpeningStock;
use App\Domain\Inventory\Actions\SetReorderLevels;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockOverview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * Demo branches and reorder levels for the admin inventory overview (local/testing only —
 * DatabaseSeeder never runs it elsewhere). Figma B · 14 Inventory 241:39494 draws three
 * stock locations; this seeds the same three, so the location cards, "Other branches",
 * the tab counts and the nav badge show real numbers:
 *
 *   dhk  Dhaka HQ · Mirpur      the migration's default location, renamed (it keeps its id
 *                               and the DemoCatalogSeeder opening stock the storefront reads)
 *   ctg  Chattogram · Agrabad   retail branch
 *   syl  Sylhet pickup point    pickup only
 *
 * Branch stock goes through the ledger as an `opening_balance` movement per variant at
 * that branch (RecordOpeningStock), never a direct stock_levels write, so the
 * movements-sum-to-levels invariant holds. Reorder points and quantities are planning
 * figures set through SetReorderLevels. Re-running changes nothing: opening stock is
 * recorded once per variant per location, and the reorder levels are absolute.
 */
class DemoInventorySeeder extends Seeder
{
    /**
     * code → [name, name_bn, description, description_bn]. The default location is
     * matched by `is_default`, not by code.
     *
     * @var array<string, array{string, string, string, string}>
     */
    public const array LOCATIONS = [
        'dhk' => ['Dhaka HQ · Mirpur', 'ঢাকা এইচকিউ · মিরপুর', 'Main warehouse · online orders', 'প্রধান গুদাম · অনলাইন অর্ডার'],
        'ctg' => ['Chattogram · Agrabad', 'চট্টগ্রাম · আগ্রাবাদ', 'Retail branch · POS + pickup', 'খুচরা শাখা · POS + পিকআপ'],
        'syl' => ['Sylhet pickup point', 'সিলেট পিকআপ পয়েন্ট', 'Pickup only · no POS', 'শুধু পিকআপ · POS নেই'],
    ];

    /** Opening units per variant at each branch, unless BRANCH_STOCK says otherwise. */
    public const array BRANCH_STOCK_DEFAULT = ['ctg' => 6, 'syl' => 2];

    /**
     * Branch opening stock exceptions, per product (every variant). The Barbie doll and
     * the dump truck are low at Dhaka HQ, the microscope is out there: Chattogram holds
     * units a transfer could draw on.
     *
     * @var array<string, array{ctg: int, syl: int}>
     */
    public const array BRANCH_STOCK = [
        'barbie-dreamhouse-pool-party-doll' => ['ctg' => 4, 'syl' => 0],
        'bruder-man-tga-dump-truck' => ['ctg' => 2, 'syl' => 1],
        'clementoni-galileo-my-first-microscope' => ['ctg' => 4, 'syl' => 0],
        'lego-classic-creative-bricks-484' => ['ctg' => 7, 'syl' => 3],
        'little-tikes-first-slide' => ['ctg' => 0, 'syl' => 0],
        'rubiks-cube-3x3-original' => ['ctg' => 12, 'syl' => 5],
        'hot-wheels-10-car-gift-pack' => ['ctg' => 9, 'syl' => 4],
    ];

    /** Reorder point / quantity per location unless REORDER says otherwise. */
    public const array REORDER_DEFAULT = [
        'dhk' => ['point' => 6, 'quantity' => 12],
        'ctg' => ['point' => 3, 'quantity' => 6],
        'syl' => ['point' => 1, 'quantity' => 2],
    ];

    /**
     * Dhaka HQ exceptions. LEGO reorders at 12 (its 9-unit box variant is low); three
     * slow movers carry a small reorder quantity, so their 24 opening units are more than
     * two reorders above the point — the Overstock tab.
     *
     * @var array<string, array{point: int, quantity: int}>
     */
    public const array REORDER_DHK = [
        'lego-classic-creative-bricks-484' => ['point' => 12, 'quantity' => 12],
        'intex-dinosaur-inflatable-kids-pool' => ['point' => 4, 'quantity' => 6],
        'winfun-baby-walker-push-car' => ['point' => 4, 'quantity' => 6],
        'melissa-doug-wooden-doctor-kit' => ['point' => 3, 'quantity' => 5],
    ];

    public function run(): void
    {
        $locations = $this->locations();
        $products = Product::query()
            ->whereIn('slug', array_keys(DemoCatalogSeeder::PRODUCTS))
            ->with('variants:id,product_id')
            ->orderBy('id')
            ->get();

        Event::fakeFor(function () use ($locations, $products): void {
            $opening = app(RecordOpeningStock::class);
            $reorder = app(SetReorderLevels::class);

            $levels = ['dhk' => [], 'ctg' => [], 'syl' => []];
            foreach ($products as $product) {
                $variantIds = $product->variants->pluck('id')->all();
                $branch = self::BRANCH_STOCK[$product->slug] ?? self::BRANCH_STOCK_DEFAULT;

                foreach (['ctg', 'syl'] as $code) {
                    $opening->handle($product, array_fill_keys($variantIds, $branch[$code]), locationId: $locations[$code]->id);
                }

                foreach ($variantIds as $variantId) {
                    $levels['dhk'][$variantId] = self::REORDER_DHK[$product->slug] ?? self::REORDER_DEFAULT['dhk'];
                    $levels['ctg'][$variantId] = self::REORDER_DEFAULT['ctg'];
                    $levels['syl'][$variantId] = self::REORDER_DEFAULT['syl'];
                }
            }

            foreach ($levels as $code => $byVariant) {
                $reorder->handle($locations[$code]->id, $byVariant);
            }
        }, [ProductChanged::class, StockLevelChanged::class]);

        StockOverview::forgetLowCounts();
    }

    /** @return array<string, StockLocation> code → location */
    private function locations(): array
    {
        $out = [];
        foreach (self::LOCATIONS as $code => [$name, $nameBn, $description, $descriptionBn]) {
            $attributes = ['code' => $code, 'name' => $name, 'name_bn' => $nameBn, 'description' => $description, 'description_bn' => $descriptionBn, 'is_active' => true];

            if ($code === 'dhk') {
                $default = StockLocation::query()->findOrFail(StockLocation::defaultId());
                $default->update($attributes);
                $out[$code] = $default;

                continue;
            }

            $out[$code] = StockLocation::query()->updateOrCreate(['code' => $code], $attributes + ['is_default' => false]);
        }

        return $out;
    }
}
