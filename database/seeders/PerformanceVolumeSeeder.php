<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Phase 9 performance profiling volume (docs/audits/PERFORMANCE.md) — NOT demo data and never
 * part of DatabaseSeeder. Run it on a throwaway database after `migrate --seed` (so the
 * reference data, categories, brands, locations, staff and one courier exist):
 *
 *     DB_CONNECTION=sqlite DB_DATABASE=/tmp/vol.sqlite php artisan db:seed --class=PerformanceVolumeSeeder
 *
 * It bulk-inserts rows straight into the tables (Actions would take hours at this volume), so
 * the build invariants do NOT hold afterwards — the point is index and query-plan behaviour at
 * launch-plus-a-year scale, not correctness. Sizes are env-overridable (PERF_ORDERS, …).
 *
 * Defaults: 5,000 products (~6,000 variants), 20,000 customers, 50,000 orders over 365 days,
 * ~150,000 order_items, ledger rows for every delivered order (~5 each), one
 * daily_financial_summaries row per day, order_financials and an invoice per delivered order.
 */
class PerformanceVolumeSeeder extends Seeder
{
    private const int CHUNK = 500;

    private Randomizer $rng;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('PerformanceVolumeSeeder never runs in production.');
        }
        $this->rng = new Randomizer(new Mt19937(20261114));

        $products = (int) (getenv('PERF_PRODUCTS') ?: 5000);
        $customers = (int) (getenv('PERF_CUSTOMERS') ?: 20000);
        $orders = (int) (getenv('PERF_ORDERS') ?: 50000);

        DB::disableQueryLog();
        if (DB::getDriverName() === 'sqlite') {
            // A throwaway profiling file: trade durability for a seed that takes minutes, not half an hour.
            DB::statement('PRAGMA synchronous = OFF');
            DB::statement('PRAGMA journal_mode = MEMORY');
        }
        $t = microtime(true);
        $variants = $this->products($products);
        $this->command?->info(sprintf('products: %d, variants: %d (%.1fs)', $products, count($variants), microtime(true) - $t));
        $customerIds = $this->customers($customers);
        $this->command?->info(sprintf('customers: %d (%.1fs)', count($customerIds), microtime(true) - $t));
        $this->orders($orders, $customerIds, $variants);
        $this->command?->info(sprintf('orders: %d (%.1fs)', $orders, microtime(true) - $t));
        $this->dailySummaries();
        $this->command?->info(sprintf('done (%.1fs)', microtime(true) - $t));
    }

    /**
     * @return list<array{id: int, product_id: int, sku: string, price: float, cost: float, name: string}>
     */
    private function products(int $count): array
    {
        $now = now()->toDateTimeString();
        $leafCategories = DB::table('categories')->whereNotNull('parent_id')->pluck('parent_id', 'id')->all();
        $brands = DB::table('brands')->pluck('id')->all();
        $image = DB::table('product_images')->first();
        $categoryIds = array_keys($leafCategories);
        $baseId = (int) DB::table('products')->max('id');

        $productRows = [];
        $pivot = [];
        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            $id = $baseId + $i;
            $category = $categoryIds[$i % count($categoryIds)];
            $status = $i % 25 === 0 ? 'draft' : ($i % 40 === 0 ? 'archived' : 'active');
            $productRows[] = [
                'id' => $id, 'name' => "Volume Toy {$i}", 'name_bn' => "ভলিউম খেলনা {$i}", 'slug' => "volume-toy-{$i}",
                'type' => $i % 5 === 0 ? 'variable' : 'simple', 'brand_id' => $brands[$i % count($brands)],
                'primary_category_id' => $category, 'short_description' => 'Profiling fixture.', 'age_min_months' => ($i % 8) * 12,
                'age_max_months' => ($i % 8) * 12 + 48, 'is_educational' => $i % 3 === 0 ? 1 : 0, 'status' => $status,
                'is_featured' => $i % 50 === 0 ? 1 : 0, 'rating_avg' => $i % 4 === 0 ? 4.2 : null, 'rating_count' => $i % 4 === 0 ? 3 : 0,
                'published_at' => $status === 'active' ? CarbonImmutable::now()->subDays($i % 300)->toDateTimeString() : null,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $pivot[] = ['category_id' => $category, 'product_id' => $id];
            $pivot[] = ['category_id' => $leafCategories[$category], 'product_id' => $id];
            if ($image !== null) {
                $images[] = [
                    'product_id' => $id, 'path' => $image->path, 'alt_text' => "Volume Toy {$i}", 'position' => 0, 'is_primary' => 1,
                    'width' => 1024, 'height' => 1024, 'processing_status' => 'ready', 'rendition_widths' => '[160,320,480,800]',
                    'media_id' => $image->media_id, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        $this->insert('products', $productRows);
        $this->insert('category_product', $pivot);
        $this->insert('product_images', $images);

        $variantRows = [];
        $variants = [];
        $variantId = (int) DB::table('product_variants')->max('id');
        foreach ($productRows as $p) {
            $n = $p['type'] === 'variable' ? 2 : 1;
            for ($v = 0; $v < $n; $v++) {
                $variantId++;
                $price = 200 + ($variantId * 37) % 4800;
                $cost = round($price * 0.68, 2);
                $sku = 'VOL-'.$p['id'].'-'.$v;
                $variantRows[] = [
                    'id' => $variantId, 'product_id' => $p['id'], 'sku' => $sku, 'name' => $n > 1 ? "Option {$v}" : null,
                    'cost_price' => $cost, 'price' => $price, 'weight_grams' => 300 + ($variantId % 20) * 100,
                    'is_default' => $v === 0 ? 1 : 0, 'position' => $v, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ];
                $variants[] = ['id' => $variantId, 'product_id' => $p['id'], 'sku' => $sku, 'price' => (float) $price, 'cost' => $cost, 'name' => $p['name']];
            }
        }
        $this->insert('product_variants', $variantRows);

        $location = (int) DB::table('stock_locations')->where('is_default', true)->value('id');
        $this->insert('stock_levels', array_map(static fn (array $v): array => [
            'variant_id' => $v['id'], 'location_id' => $location, 'on_hand' => $v['id'] % 13 === 0 ? 0 : 40, 'reserved' => 0,
            'reorder_point' => 5, 'avg_cost' => $v['cost'], 'created_at' => $now, 'updated_at' => $now,
        ], $variants));

        return $variants;
    }

    /** @return list<int> */
    private function customers(int $count): array
    {
        $baseId = (int) DB::table('customers')->max('id');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $created = CarbonImmutable::now()->subDays(400 - ($i % 400))->toDateTimeString();
            $rows[] = [
                'id' => $baseId + $i, 'name' => $i % 2 === 0 ? "Volume Buyer {$i}" : "ভলিউম ক্রেতা {$i}",
                'phone' => '+88017'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'preferred_language' => $i % 2 === 0 ? 'en' : 'bn', 'source' => 'web', 'status' => 'active',
                'created_at' => $created, 'updated_at' => $created,
            ];
        }
        $this->insert('customers', $rows);

        return range($baseId + 1, $baseId + $count);
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<array{id: int, product_id: int, sku: string, price: float, cost: float, name: string}>  $variants
     */
    private function orders(int $count, array $customerIds, array $variants): void
    {
        $statuses = ['delivered' => 46, 'completed' => 10, 'cancelled' => 12, 'returned' => 4, 'shipped' => 8, 'confirmed' => 7,
            'processing' => 4, 'pending' => 6, 'on_hold' => 3];
        $bag = [];
        foreach ($statuses as $s => $w) {
            array_push($bag, ...array_fill(0, $w, $s));
        }
        $sources = ['web', 'web', 'web', 'phone', 'facebook', 'whatsapp'];
        $orderId = (int) DB::table('orders')->max('id');
        $itemId = (int) DB::table('order_items')->max('id');
        $provider = DB::table('courier_providers')->value('id');
        $staff = DB::table('users')->value('id');
        $now = CarbonImmutable::now('UTC');
        $seq = 0;

        $orders = $items = $financials = $ledger = $invoices = $shipments = $payments = [];
        for ($i = 0; $i < $count; $i++) {
            $orderId++;
            $seq++;
            $status = $bag[$this->rng->getInt(0, count($bag) - 1)];
            $placed = $now->subMinutes($this->rng->getInt(60, 365 * 24 * 60));
            $customer = $customerIds[$this->rng->getInt(0, count($customerIds) - 1)];
            $lineCount = $this->rng->getInt(1, 5);
            $subtotal = 0.0;
            $cogs = 0.0;
            $units = 0;
            for ($l = 0; $l < $lineCount; $l++) {
                $v = $variants[$this->rng->getInt(0, count($variants) - 1)];
                $qty = $this->rng->getInt(1, 2);
                $subtotal += $v['price'] * $qty;
                $cogs += $v['cost'] * $qty;
                $units += $qty;
                $itemId++;
                $items[] = [
                    'id' => $itemId, 'order_id' => $orderId, 'variant_id' => $v['id'], 'product_id' => $v['product_id'], 'product_name' => $v['name'],
                    'sku' => $v['sku'], 'unit_price' => $v['price'], 'quantity' => $qty, 'line_total' => $v['price'] * $qty,
                    'cost_price_snapshot' => $v['cost'], 'quantity_shipped' => in_array($status, ['delivered', 'completed', 'shipped', 'returned'], true) ? $qty : 0,
                    'created_at' => $placed->toDateTimeString(), 'updated_at' => $placed->toDateTimeString(),
                ];
            }
            $shipping = $subtotal > 3000 ? 0.0 : 130.0;
            $grand = $subtotal + $shipping;
            $delivered = in_array($status, ['delivered', 'completed', 'returned'], true);
            $deliveredAt = $delivered || $status === 'shipped' ? $placed->addDays(3) : null;
            $deliveredAt = $deliveredAt !== null && $deliveredAt->greaterThan($now) ? $now : $deliveredAt;
            $orders[] = [
                'id' => $orderId, 'number' => sprintf('VOL-%07d', $seq), 'customer_id' => $customer, 'status' => $status,
                'payment_status' => $delivered ? 'paid' : 'unpaid', 'fulfillment_status' => $delivered ? 'fulfilled' : 'unfulfilled',
                'source' => $sources[$i % count($sources)], 'payment_method' => 'cod', 'subtotal' => $subtotal, 'shipping_charge' => $shipping,
                'grand_total' => $grand, 'paid_total' => $delivered ? $grand : 0, 'due_total' => $delivered ? 0 : $grand,
                'shipping_name' => 'Volume Buyer', 'shipping_phone' => '+88017'.str_pad((string) (10000000 + ($customer % 99999)), 8, '0', STR_PAD_LEFT),
                'shipping_division_id' => 1, 'shipping_district_id' => 1 + ($i % 60), 'shipping_division' => 'Dhaka', 'shipping_district' => 'Dhaka',
                'shipping_address' => 'House 1, Road 2', 'shipping_landmark' => 'Near the mosque',
                'risk_score' => $i % 17, 'risk_level' => $status === 'on_hold' ? 'high' : 'low',
                'verification_status' => $status === 'on_hold' ? 'pending' : 'not_required',
                'placed_at' => $placed->toDateTimeString(), 'confirmed_at' => $status !== 'pending' ? $placed->addHours(2)->toDateTimeString() : null,
                'shipped_at' => $deliveredAt?->subDays(1)->toDateTimeString(),
                'delivered_at' => $delivered ? $deliveredAt?->toDateTimeString() : null,
                'cancelled_at' => $status === 'cancelled' ? $placed->addHours(5)->toDateTimeString() : null,
                'returned_at' => $status === 'returned' ? $deliveredAt?->toDateTimeString() : null,
                'created_by' => $i % 6 >= 3 ? $staff : null,
                'created_at' => $placed->toDateTimeString(), 'updated_at' => ($deliveredAt ?? $placed)->toDateTimeString(),
            ];
            if ($status === 'shipped' || $delivered) {
                $shipments[] = [
                    'order_id' => $orderId, 'provider_id' => $provider, 'number' => sprintf('VSH-%07d', $seq), 'consignment_id' => 'VC'.$seq,
                    'status' => $status === 'shipped' ? 'in_transit' : ($status === 'returned' ? 'returned' : 'delivered'),
                    'weight_grams' => 500, 'item_count' => $units, 'cod_amount' => $grand, 'declared_value' => $grand, 'charge' => 130,
                    'booked_at' => $deliveredAt?->subDays(1)->toDateTimeString(), 'delivered_at' => $delivered ? $deliveredAt?->toDateTimeString() : null,
                    'created_at' => $placed->toDateTimeString(), 'updated_at' => $placed->toDateTimeString(),
                ];
            }
            $payments[] = [
                'order_id' => $orderId, 'method' => 'cod', 'amount' => $grand, 'status' => $delivered ? 'paid' : 'pending',
                'paid_at' => $delivered ? $deliveredAt?->toDateTimeString() : null, 'created_at' => $placed->toDateTimeString(), 'updated_at' => $placed->toDateTimeString(),
            ];
            if ($delivered && $deliveredAt !== null) {
                $on = $deliveredAt->setTimezone('Asia/Dhaka')->toDateString();
                $profit = $subtotal - $cogs;
                $financials[] = [
                    'order_id' => $orderId, 'revenue' => $grand, 'cogs' => $cogs, 'gross_profit' => $profit, 'courier_charge' => 130,
                    'net_profit' => $profit - 130, 'margin_percent' => $grand > 0 ? round(($profit - 130) / $grand * 100, 2) : null,
                    'computed_at' => $deliveredAt->toDateTimeString(),
                ];
                foreach ([
                    ['sale_revenue', 'in', $subtotal, 0, 1], ['shipping_revenue', 'in', $shipping, 0, 1], ['cogs', 'out', $cogs, 0, 1],
                    ['courier_charge', 'out', 130, 0, 1], ['cod_received', 'in', $grand, 1, 0],
                ] as [$type, $dir, $amount, $cash, $recognised]) {
                    if ($amount <= 0) {
                        continue;
                    }
                    $ledger[] = [
                        'type' => $type, 'direction' => $dir, 'amount' => $amount, 'occurred_on' => $on, 'source_type' => 'order',
                        'source_id' => $orderId, 'description' => 'Volume order', 'is_cash_movement' => $cash, 'is_recognised_revenue' => $recognised,
                        'created_at' => $deliveredAt->toDateTimeString(),
                    ];
                }
                $year = (int) $deliveredAt->format('Y');
                $invoices[] = [
                    'type' => 'sales', 'series' => 'VINV', 'year' => $year, 'sequence' => $seq, 'number' => sprintf('VINV-%07d', $seq),
                    'order_id' => $orderId, 'reference' => sprintf('VOL-%07d', $seq), 'issued_to' => '{"name":"Volume Buyer"}',
                    'issued_by' => '{"name":"Toy Mart BD"}', 'lines' => '[]', 'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $grand,
                    'paid' => $grand, 'payment_method' => 'cod', 'issued_at' => $deliveredAt->toDateTimeString(), 'kind' => 'original',
                    'created_at' => $deliveredAt->toDateTimeString(), 'updated_at' => $deliveredAt->toDateTimeString(),
                ];
            }

            if (count($orders) >= self::CHUNK) {
                $this->flush($orders, $items, $financials, $ledger, $invoices, $shipments, $payments);
            }
        }
        $this->flush($orders, $items, $financials, $ledger, $invoices, $shipments, $payments);

        // Customer aggregates (customers list sorts by these) — one set-based update.
        DB::statement(<<<'SQL'
            UPDATE customers SET
              orders_count = (SELECT COUNT(*) FROM orders o WHERE o.customer_id = customers.id),
              delivered_orders_count = (SELECT COUNT(*) FROM orders o WHERE o.customer_id = customers.id AND o.status IN ('delivered','completed')),
              total_spent = COALESCE((SELECT SUM(grand_total) FROM orders o WHERE o.customer_id = customers.id AND o.status IN ('delivered','completed')), 0),
              first_order_at = (SELECT MIN(placed_at) FROM orders o WHERE o.customer_id = customers.id),
              last_order_at = (SELECT MAX(placed_at) FROM orders o WHERE o.customer_id = customers.id)
            SQL);
    }

    /**
     * @param  array<int, array<string, mixed>>  ...$buffers
     */
    private function flush(array &...$buffers): void
    {
        $tables = ['orders', 'order_items', 'order_financials', 'financial_transactions', 'invoices', 'shipments', 'payments'];
        DB::transaction(function () use (&$buffers, $tables): void {
            foreach ($tables as $i => $table) {
                $this->insert($table, $buffers[$i]);
                $buffers[$i] = [];
            }
        });
    }

    private function dailySummaries(): void
    {
        $rows = DB::table('financial_transactions')
            ->selectRaw("occurred_on,
                SUM(CASE WHEN type='sale_revenue' THEN amount ELSE 0 END) AS sale_revenue,
                SUM(CASE WHEN type='shipping_revenue' THEN amount ELSE 0 END) AS shipping_revenue,
                SUM(CASE WHEN type='cogs' THEN amount ELSE 0 END) AS cogs,
                SUM(CASE WHEN type='courier_charge' THEN amount ELSE 0 END) AS courier_charge,
                SUM(CASE WHEN type='cod_received' THEN amount ELSE 0 END) AS cod_received,
                SUM(CASE WHEN is_recognised_revenue = 1 AND direction='in' THEN amount ELSE 0 END) AS recognised_in,
                SUM(CASE WHEN is_recognised_revenue = 1 AND direction='out' THEN amount ELSE 0 END) AS recognised_out,
                SUM(CASE WHEN is_cash_movement = 1 AND direction='in' THEN amount ELSE 0 END) AS cash_in")
            ->groupBy('occurred_on')->get();
        DB::table('daily_financial_summaries')->delete();
        $this->insert('daily_financial_summaries', $rows->map(static fn (object $r): array => [
            'summary_date' => $r->occurred_on, 'sale_revenue' => $r->sale_revenue, 'shipping_revenue' => $r->shipping_revenue,
            'cogs' => $r->cogs, 'courier_charge' => $r->courier_charge, 'cod_received' => $r->cod_received,
            'recognised_in' => $r->recognised_in, 'recognised_out' => $r->recognised_out,
            'net_profit' => (float) $r->recognised_in - (float) $r->recognised_out, 'cash_in' => $r->cash_in, 'net_cash' => $r->cash_in,
            'computed_at' => now()->toDateTimeString(),
        ])->all());
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
