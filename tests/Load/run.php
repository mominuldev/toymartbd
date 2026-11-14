<?php

declare(strict_types=1);

/*
 * Load and concurrency harness (Phase 9) — see tests/Load/README.md.
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=/tmp/x/load.sqlite php tests/Load/run.php
 *   DB_CONNECTION=mysql DB_DATABASE=toymartbd_load ... php tests/Load/run.php --queue-workers=4
 *
 * It WIPES the database it is pointed at (migrate:fresh), so it refuses unless the
 * database name contains "load" and APP_ENV is not production. It starts its own PHP
 * server (`php -S`, N workers) on --port, drives three scenarios over HTTP with curl_multi,
 * checks the invariants in the database, prints a report and exits non-zero on failure.
 */

use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Marketing\Promotions\PromoKit;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Load\LoadHttp;
use Tests\Support\CourierFixtures;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$opt = getopt('', ['port::', 'workers::', 'checkout-vus::', 'flash-vus::', 'flash-allotment::', 'bulk-orders::', 'bulk-staff::', 'queue-workers::', 'scenario::', 'report::', 'p95-ms::']);
$cfg = [
    'port' => (int) ($opt['port'] ?? 8341),
    'workers' => (int) ($opt['workers'] ?? 16),
    'checkout_vus' => (int) ($opt['checkout-vus'] ?? 50),
    'flash_vus' => (int) ($opt['flash-vus'] ?? 50),
    'flash_allotment' => (int) ($opt['flash-allotment'] ?? 20),
    'bulk_orders' => (int) ($opt['bulk-orders'] ?? 100),
    'bulk_staff' => (int) ($opt['bulk-staff'] ?? 10),
    'queue_workers' => (int) ($opt['queue-workers'] ?? 1),
    'scenarios' => explode(',', (string) ($opt['scenario'] ?? 'checkout,flash,bulk')),
    'report' => isset($opt['report']) ? (string) $opt['report'] : null,
    'p95_ms' => (float) ($opt['p95-ms'] ?? 5000),
];

// ---------------------------------------------------------------- environment + guard
$database = (string) getenv('DB_DATABASE');
if (! str_contains(strtolower($database), 'load')) {
    fwrite(STDERR, "Refusing: DB_DATABASE must name a disposable database containing \"load\" (got \"{$database}\"). This harness runs migrate:fresh.\n");
    exit(2);
}
if (getenv('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing: APP_ENV=production.\n");
    exit(2);
}
$base = 'http://127.0.0.1:'.$cfg['port'];
$env = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'false',
    'APP_URL' => $base,
    'QUEUE_CONNECTION' => 'database',      // courier jobs wait for the harness's NullCourierGateway workers
    'CACHE_STORE' => 'database',           // shared across server workers: rate limits, idempotency locks
    'SESSION_DRIVER' => 'database',
    'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:'.$cfg['port'],
    'TRUSTED_PROXIES' => '127.0.0.1',      // each virtual shopper sends its own X-Forwarded-For, as behind Next.js
    'SCOUT_DRIVER' => 'null',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'LOG_CHANNEL' => 'stderr',
    'LOG_LEVEL' => 'error',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];
if (getenv('DB_CONNECTION') === 'sqlite') {
    // One SQLite file, many writers: queue for the write lock instead of failing at once.
    // SQLite then serialises every write — this run proves correctness, not throughput.
    $env += ['DB_BUSY_TIMEOUT' => '60000', 'DB_JOURNAL_MODE' => 'wal', 'DB_TRANSACTION_MODE' => 'IMMEDIATE'];
}
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$driver = DB::connection()->getDriverName();
$innodbDeadlocks = static fn (): ?int => $driver === 'mysql'
    ? (int) (DB::selectOne("SELECT `count` AS c FROM information_schema.INNODB_METRICS WHERE name = 'lock_deadlocks'")->c ?? 0)
    : null;
$deadlocksBefore = $innodbDeadlocks();

$tmp = sys_get_temp_dir().'/toymart-load-'.getmypid();
@mkdir($tmp, 0777, true);

$say = static function (string $line): void {
    fwrite(STDOUT, $line."\n");
};

$say("Load harness on {$driver} ({$database}), server {$base} with {$cfg['workers']} workers");
Artisan::call('migrate:fresh', ['--force' => true]);
foreach ([GeographySeeder::class, DeliverySeeder::class, RolesAndPermissionsSeeder::class] as $seeder) {
    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}
config(['risk.first_order_hold_threshold' => '100000.00']);
$storefrontToken = CartTestKit::storefrontToken();

// ---------------------------------------------------------------- server
$serverLog = $tmp.'/server.log';
DB::disconnect();
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$cfg['port'], '-t', $root.'/public', $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    $root.'/public', // server.php resolves index.php from the working directory, as `artisan serve` does
    getenv() + ['PHP_CLI_SERVER_WORKERS' => (string) $cfg['workers']],
);
if (! is_resource($server)) {
    fwrite(STDERR, "Could not start the server.\n");
    exit(1);
}
register_shutdown_function(static function () use ($server): void {
    $status = proc_get_status($server);
    if ($status['running']) {
        // php -S workers are children of the master: end the whole group.
        exec('pkill -TERM -P '.(int) $status['pid']);
        proc_terminate($server);
    }
});
$http = new LoadHttp($base);
$deadline = microtime(true) + 30;
do {
    usleep(200_000);
    $ping = $http->burst([['method' => 'GET', 'path' => '/api/v1/storefront/ping']])[0];
} while (($ping['json']['ok'] ?? null) !== true && microtime(true) < $deadline);
if (($ping['json']['ok'] ?? null) !== true) {
    fwrite(STDERR, "Server did not come up; see {$serverLog}\n");
    exit(1);
}

// ---------------------------------------------------------------- helpers
$sf = static fn (string $ip, array $extra = []): array => ['Authorization' => 'Bearer '.$storefrontToken, 'X-Forwarded-For' => $ip] + $extra;
$ip = static fn (int $i): string => sprintf('10.%d.%d.%d', 1 + intdiv($i, 65536) % 250, intdiv($i, 256) % 256, $i % 256 + 1);
$find = static function (mixed $json, string $key) use (&$find): mixed {
    if (! is_array($json)) {
        return null;
    }
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }
    foreach ($json as $value) {
        $found = $find($value, $key);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
};
$problem = static fn (array $r): string => is_array($r['json']) && isset($r['json']['type']) ? basename((string) $r['json']['type']) : '';
$stats = static function (array $results): array {
    $ms = array_map(static fn (array $r): float => $r['ms'], $results);

    return ['n' => count($ms), 'p50_ms' => LoadHttp::percentile($ms, 50), 'p95_ms' => LoadHttp::percentile($ms, 95), 'max_ms' => $ms === [] ? 0 : max($ms)];
};
$tally = static function (array $results) use ($problem): array {
    $t = [];
    foreach ($results as $r) {
        $key = $r['error'] !== null ? 'transport' : $r['status'].($problem($r) !== '' ? ' '.$problem($r) : '').(($r['headers']['idempotent-replayed'] ?? '') === 'true' ? ' replayed' : '');
        $t[$key] = ($t[$key] ?? 0) + 1;
    }
    ksort($t);

    return $t;
};
$failures = [];
/** The first response outside the expected outcomes, for the failure detail. */
$sample = static function (array $results, array $expected) use ($problem): string {
    foreach ($results as $r) {
        $key = $r['error'] !== null ? 'transport' : $r['status'].($problem($r) !== '' ? ' '.$problem($r) : '').(($r['headers']['idempotent-replayed'] ?? '') === 'true' ? ' replayed' : '');
        if (! in_array($key, $expected, true)) {
            return ' · e.g. '.$key.' '.mb_substr(strip_tags($r['error'] ?? $r['body']), 0, 600);
        }
    }

    return '';
};
$check = static function (string $scenario, string $what, bool $ok, string $detail = '') use (&$failures, $say): void {
    $say(sprintf('  [%s] %s%s', $ok ? 'PASS' : 'FAIL', $what, $detail !== '' ? ' — '.$detail : ''));
    if (! $ok) {
        $failures[] = $scenario.': '.$what.($detail !== '' ? ' — '.$detail : '');
    }
};
/** Builds carts over HTTP (one line per round so every round is itself a burst) and quotes them. */
$buildCarts = static function (array $carts) use ($http, $sf, $ip, $find): array {
    $tokens = array_fill_keys(array_keys($carts), null);
    $rounds = max(array_map('count', $carts));
    $cartResults = [];
    for ($round = 0; $round < $rounds; $round++) {
        $requests = [];
        $map = [];
        foreach ($carts as $i => $lines) {
            $keys = array_keys($lines);
            if (! isset($keys[$round])) {
                continue;
            }
            $headers = $sf($ip($i));
            if ($tokens[$i] !== null) {
                $headers['X-Cart-Token'] = $tokens[$i];
            }
            $requests[] = ['method' => 'POST', 'path' => '/api/v1/storefront/cart/items', 'headers' => $headers, 'json' => ['variant_id' => $keys[$round], 'quantity' => $lines[$keys[$round]]]];
            $map[] = $i;
        }
        foreach ($http->burst($requests) as $n => $r) {
            $cartResults[] = $r;
            $tokens[$map[$n]] ??= $find($r['json'], 'token');
        }
    }
    $quotes = $http->burst(array_map(static fn (int $i): array => [
        'method' => 'GET',
        'path' => '/api/v1/storefront/cart?district_id='.CheckoutKit::DISTRICT.'&upazila_id='.CheckoutKit::UPAZILA,
        'headers' => $sf($ip($i), ['X-Cart-Token' => (string) $tokens[$i]]),
    ], array_keys($carts)));

    return ['tokens' => array_values($tokens), 'totals' => array_map(static fn (array $r): mixed => $find($r['json'], 'grand_total'), $quotes), 'results' => array_merge($cartResults, $quotes)];
};
$checkoutRequest = static fn (int $i, string $token, string $total, string $key): array => [
    'method' => 'POST',
    'path' => '/api/v1/storefront/checkout',
    'headers' => $sf($ip($i), ['X-Cart-Token' => $token, 'Idempotency-Key' => $key]),
    'json' => [
        'name' => $i % 2 === 0 ? 'রাহেলা খাতুন' : 'Rahela Khatun',
        'phone' => sprintf('0171%07d', $i),
        'district_id' => CheckoutKit::DISTRICT,
        'upazila_id' => CheckoutKit::UPAZILA,
        'area' => 'আগ্রাবাদ',
        'address_line' => 'বাড়ি ১২, রোড ৫, ব্লক সি',
        'landmark' => 'জামে মসজিদের পাশে',
        'payment_method' => 'cod',
        'expected_total' => $total,
    ],
];
$invariants = static function (string $scenario) use ($check): void {
    $stock = app(ReconcileStockLevels::class)->handle();
    $check($scenario, 'stock movements sum to stock levels', ($stock['drift_count'] ?? null) === 0, json_encode($stock, JSON_THROW_ON_ERROR));
    $drift = app(FinanceInvariant::class)->drift();
    $check($scenario, 'order financials equal the ledger', $drift === [], $drift === [] ? '' : json_encode($drift, JSON_THROW_ON_ERROR));
    $numbers = Order::query()->pluck('number')->all();
    $check($scenario, 'order numbers unique', count($numbers) === count(array_unique($numbers)), count($numbers).' orders');
    $seq = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $numbers);
    sort($seq);
    $check($scenario, 'order numbers gap-free', $seq === ($seq === [] ? [] : range(1, count($seq))));
};
$report = ['driver' => $driver, 'config' => $cfg, 'scenarios' => []];
$errorRate = static function (array $tally, array $expected): float {
    $total = array_sum($tally);
    $bad = 0;
    foreach ($tally as $key => $n) {
        if (! in_array((string) $key, $expected, true)) {
            $bad += $n;
        }
    }

    return $total === 0 ? 0.0 : round($bad / $total, 4);
};

// ---------------------------------------------------------------- 1. concurrent checkout
if (in_array('checkout', $cfg['scenarios'], true)) {
    $say("\n1. Concurrent checkout — {$cfg['checkout_vus']} shoppers press \"place order\" at once");
    mt_srand(9);
    $variants = [];
    foreach (['450.00', '600.00', '750.00', '900.00', '1200.00'] as $price) {
        $variants[] = CartTestKit::variant($price, (int) ceil($cfg['checkout_vus'] * 0.8))->id;
    }
    $initial = StockLevel::query()->pluck('on_hand', 'variant_id')->map(static fn (mixed $v): int => (int) $v)->all();
    $carts = [];
    for ($i = 0; $i < $cfg['checkout_vus']; $i++) {
        $pick = $variants;
        shuffle($pick);
        $lines = [];
        foreach (array_slice($pick, 0, mt_rand(1, 3)) as $variantId) {
            $lines[$variantId] = mt_rand(1, 3);
        }
        $carts[] = $lines;
    }
    DB::disconnect();
    $built = $buildCarts($carts);
    $requests = [];
    $owners = [];
    foreach ($carts as $i => $lines) {
        $key = (string) Str::uuid();
        $requests[] = $checkoutRequest($i, (string) $built['tokens'][$i], (string) $built['totals'][$i], $key);
        $owners[] = $i;
        if ($i % 10 === 0) { // a double tap: same key, same body, same moment
            $requests[] = $checkoutRequest($i, (string) $built['tokens'][$i], (string) $built['totals'][$i], $key);
            $owners[] = $i;
        }
    }
    $started = microtime(true);
    $results = $http->burst($requests);
    $wall = round((microtime(true) - $started) * 1000);
    $t = $tally($results);
    $s = $stats($results);
    $say('  outcomes '.json_encode($t).' · cart+quote '.json_encode($stats($built['results'])).' · checkout '.json_encode($s)." · burst wall {$wall} ms");

    $expected = ['201', '201 replayed', '409 insufficient-stock', '409 request-in-progress'];
    $rate = $errorRate($t, $expected);
    $check('checkout', 'error rate 0 (only 201 / insufficient-stock / in-progress)', $rate === 0.0, 'error rate '.$rate.$sample($results, $expected));
    $check('checkout', 'cart building error-free', $errorRate($tally($built['results']), ['200', '201']) === 0.0, json_encode($tally($built['results'])));
    $placed = Order::query()->count();
    $created = count(array_filter($results, static fn (array $r): bool => $r['status'] === 201 && ($r['headers']['idempotent-replayed'] ?? '') !== 'true'));
    $check('checkout', 'one order per accepted checkout', $placed === $created, "{$placed} orders, {$created} fresh 201s");
    $perCart = DB::table('orders')->select('cart_id', DB::raw('count(*) as c'))->groupBy('cart_id')->having('c', '>', 1)->count();
    $check('checkout', 'double taps never made a second order', $perCart === 0);
    $sold = DB::table('order_items')->select('variant_id', DB::raw('sum(quantity) as q'))->groupBy('variant_id')->pluck('q', 'variant_id')->all();
    $levels = StockLevel::query()->get()->keyBy('variant_id');
    $oversold = [];
    foreach ($variants as $variantId) {
        $q = (int) ($sold[$variantId] ?? 0);
        $level = $levels[$variantId];
        if ($q > $initial[$variantId] || $level->reserved !== $q || $level->on_hand !== $initial[$variantId]) {
            $oversold[$variantId] = ['sold' => $q, 'stock' => $initial[$variantId], 'reserved' => $level->reserved, 'on_hand' => $level->on_hand];
        }
    }
    $check('checkout', 'no oversell: sold ≤ stock, reserved = sold', $oversold === [], json_encode($oversold).' sold '.json_encode($sold));
    $check('checkout', "p95 ≤ {$cfg['p95_ms']} ms", $s['p95_ms'] <= $cfg['p95_ms'], $s['p95_ms'].' ms');
    $invariants('checkout');
    $report['scenarios']['checkout'] = ['outcomes' => $t, 'latency' => $s, 'cart_latency' => $stats($built['results']), 'wall_ms' => $wall, 'orders' => $placed, 'sold' => $sold, 'error_rate' => $rate];
}

// ---------------------------------------------------------------- 2. flash-sale burst
if (in_array('flash', $cfg['scenarios'], true)) {
    $say("\n2. Flash-sale burst — {$cfg['flash_vus']} shoppers, allotment {$cfg['flash_allotment']}, 2 per order");
    $variant = CartTestKit::variant('1000.00', $cfg['flash_vus'] * 4);
    $promotion = PromoKit::flash([$variant->id => ['800.00', $cfg['flash_allotment'], 2]]);
    $ordersBefore = Order::query()->count();
    $carts = array_fill(0, $cfg['flash_vus'], [$variant->id => 2]);
    $offset = 100_000; // distinct shoppers (phones, IPs) from scenario 1
    DB::disconnect();
    $built = $buildCarts(array_combine(range($offset, $offset + $cfg['flash_vus'] - 1), $carts));
    $tokens = array_values($built['tokens']);
    $totals = array_values($built['totals']);
    $requests = [];
    foreach ($tokens as $n => $token) {
        $requests[] = $checkoutRequest($offset + $n, (string) $token, (string) $totals[$n], (string) Str::uuid());
    }
    $results = $http->burst($requests);
    $t1 = $tally($results);
    $s1 = $stats($results);
    // A shopper told "price changed" re-quotes and presses again (docs/api/checkout.md).
    $again = [];
    foreach ($results as $n => $r) {
        if ($r['status'] === 409 && $problem($r) === 'price-changed') {
            $again[] = $n;
        }
    }
    $requotes = $http->burst(array_map(static fn (int $n): array => [
        'method' => 'GET',
        'path' => '/api/v1/storefront/cart?district_id='.CheckoutKit::DISTRICT.'&upazila_id='.CheckoutKit::UPAZILA,
        'headers' => $sf($ip($offset + $n), ['X-Cart-Token' => (string) $tokens[$n]]),
    ], $again));
    $retry = $http->burst(array_map(static fn (int $k): array => $checkoutRequest($offset + $again[$k], (string) $tokens[$again[$k]], (string) $find($requotes[$k]['json'], 'grand_total'), (string) Str::uuid()), array_keys($again)));
    $t2 = $tally($retry);
    $say('  first press '.json_encode($t1).' '.json_encode($s1).' · after re-quote '.json_encode($t2).' '.json_encode($stats($retry)));

    $rate = $errorRate($t1, ['201', '409 price-changed']);
    $check('flash', 'first burst: only 201 or price-changed (allotment gone)', $rate === 0.0, 'error rate '.$rate.$sample($results, ['201', '409 price-changed']));
    $check('flash', 're-quoted presses all placed at the regular price', $errorRate($t2, ['201']) === 0.0, json_encode($t2));
    $item = DB::table('flash_sale_items')->where('promotion_id', $promotion->id)->first();
    $units = 0;
    foreach (DB::table('promotion_usages')->where('promotion_id', $promotion->id)->pluck('flash_units') as $json) {
        $units += array_sum(array_map('intval', (array) json_decode((string) $json, true)));
    }
    $discount = (string) DB::table('orders')->where('id', '>', 0)->whereIn('cart_id', DB::table('carts')->whereIn('token', $tokens)->select('id'))->sum('promotion_discount');
    $check('flash', 'allotment never exceeded', (int) $item->claimed <= $cfg['flash_allotment'], "claimed {$item->claimed} of {$cfg['flash_allotment']}");
    $check('flash', 'allotment fully sold under contention', (int) $item->claimed === $cfg['flash_allotment']);
    $check('flash', 'Σ flash units on orders = claimed', $units === (int) $item->claimed, "{$units} units");
    $check('flash', 'Σ promotion discount = claimed × ৳200', bccomp($discount, (string) ((int) $item->claimed * 200), 2) === 0, $discount);
    $check('flash', 'every shopper ended with exactly one order', Order::query()->count() - $ordersBefore === $cfg['flash_vus']);
    $check('flash', "p95 ≤ {$cfg['p95_ms']} ms", $s1['p95_ms'] <= $cfg['p95_ms'], $s1['p95_ms'].' ms');
    $invariants('flash');
    $report['scenarios']['flash'] = ['first_press' => $t1, 'latency' => $s1, 'after_requote' => $t2, 'claimed' => (int) $item->claimed, 'flash_units' => $units, 'error_rate' => $rate];
}

// ---------------------------------------------------------------- 3. courier bulk booking
if (in_array('bulk', $cfg['scenarios'], true)) {
    $say("\n3. Courier bulk booking — {$cfg['bulk_staff']} staff bulk-send the same {$cfg['bulk_orders']} orders at once");
    CourierFixtures::provider('steadfast', ['supports_bulk' => true]);
    $variant = OrderFixtures::stockedVariant($cfg['bulk_orders'] * 2, '600.00', '350.00');
    $orderIds = [];
    for ($i = 0; $i < $cfg['bulk_orders']; $i++) {
        $orderIds[] = app(TransitionOrder::class)->handle(OrderFixtures::place([$variant->id => 1]), OrderStatus::Confirmed)->id;
    }
    $staff = [];
    for ($i = 0; $i < $cfg['bulk_staff']; $i++) {
        $staff[] = User::factory()->withRole(Role::OrderOperator)->create(['email' => "load-staff-{$i}@toymart.test"]);
    }
    DB::disconnect();

    // Sanctum SPA sign-in per staff member: CSRF cookie, then login, each from its own IP.
    $jars = array_map(static fn (int $i): string => $tmp."/jar-{$i}.txt", array_keys($staff));
    $origin = ['Origin' => $base, 'Referer' => $base.'/admin'];
    $http->burst(array_map(static fn (int $i): array => ['method' => 'GET', 'path' => '/sanctum/csrf-cookie', 'headers' => $origin + ['X-Forwarded-For' => '192.168.9.'.($i + 1)], 'jar' => $jars[$i]], array_keys($staff)));
    $xsrf = static function (string $jar): string {
        foreach (file($jar) ?: [] as $line) {
            $cols = explode("\t", trim($line));
            if (count($cols) === 7 && $cols[5] === 'XSRF-TOKEN') {
                return urldecode($cols[6]);
            }
        }

        return '';
    };
    $logins = $http->burst(array_map(static fn (int $i): array => [
        'method' => 'POST', 'path' => '/api/v1/admin/auth/login', 'jar' => $jars[$i],
        'headers' => $origin + ['X-Forwarded-For' => '192.168.9.'.($i + 1), 'X-XSRF-TOKEN' => $xsrf($jars[$i])],
        'json' => ['email' => "load-staff-{$i}@toymart.test", 'password' => 'password'],
    ], array_keys($staff)));
    $check('bulk', 'every staff member signed in', $errorRate($tally($logins), ['200', '204']) === 0.0, json_encode($tally($logins)));

    $requests = [];
    foreach (array_keys($staff) as $i) {
        $ids = $orderIds;
        if ($i % 2 === 1) {
            $ids = array_reverse($ids);
        } else {
            shuffle($ids);
        }
        $requests[] = [
            'method' => 'POST', 'path' => '/api/v1/admin/shipments/bulk', 'jar' => $jars[$i],
            'headers' => $origin + ['X-Forwarded-For' => '192.168.9.'.($i + 1), 'X-XSRF-TOKEN' => $xsrf($jars[$i]), 'Idempotency-Key' => (string) Str::uuid()],
            'json' => ['order_ids' => array_slice($ids, 0, 100)],
        ];
    }
    $results = $http->burst($requests);
    $t = $tally($results);
    $s = $stats($results);
    $requested = array_sum(array_map(static fn (array $r): int => (int) ($r['json']['meta']['requested'] ?? 0), $results));
    $say('  bulk requests '.json_encode($t).' '.json_encode($s)." · requested {$requested}");
    $check('bulk', 'every bulk request answered 200', $errorRate($t, ['200']) === 0.0, json_encode($t).$sample($results, ['200']));
    $check('bulk', 'each order requested exactly once across all staff', $requested === min(100, $cfg['bulk_orders']), "{$requested} requested");

    // Book through NullCourierGateway: the harness runs the courier queue itself, W workers.
    $workers = max(1, $cfg['queue_workers']);
    $pids = [];
    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $gateway = new NullCourierGateway('steadfast', false, true, 'null-secret', '70.00');
            app(CourierRegistry::class)->register('steadfast', $gateway);
            Artisan::call('queue:work', ['--queue' => 'courier', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3]);
            $seen = [];
            foreach ($gateway->bulkBooked as $batch) {
                foreach ($batch as $request) {
                    $seen[] = $request instanceof ShipmentRequest ? $request->shipmentId : 0;
                }
            }
            foreach ($gateway->booked as $request) {
                $seen[] = $request->shipmentId;
            }
            file_put_contents($tmp."/worker-{$w}.json", json_encode($seen, JSON_THROW_ON_ERROR));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $seen = [];
    for ($w = 0; $w < $workers; $w++) {
        $seen = array_merge($seen, (array) json_decode((string) @file_get_contents($tmp."/worker-{$w}.json"), true));
    }
    DB::reconnect();
    $shipments = Shipment::query()->get();
    $statuses = $shipments->countBy(static fn (Shipment $s): string => $s->status->value)->all();
    $counts = array_count_values(array_map('intval', $seen));
    $check('bulk', 'one shipment per order', $shipments->count() === $cfg['bulk_orders'] && $shipments->pluck('order_id')->unique()->count() === $cfg['bulk_orders'], $shipments->count().' shipments');
    $check('bulk', 'shipment numbers unique', $shipments->pluck('number')->unique()->count() === $shipments->count());
    $check('bulk', 'every shipment submitted (bulk returns no consignment ids, rule 24d)', ($statuses[ShipmentStatus::Submitted->value] ?? 0) === $shipments->count(), json_encode($statuses));
    $check('bulk', 'the courier received each shipment exactly once', count($counts) === $shipments->count() && max($counts ?: [0]) === 1, count($seen).' sent for '.count($counts).' shipments');
    $check('bulk', 'no failed courier jobs, queue drained', DB::table('failed_jobs')->count() === 0 && DB::table('jobs')->where('queue', 'courier')->count() === 0);
    $invariants('bulk');
    $report['scenarios']['bulk'] = ['requests' => $t, 'latency' => $s, 'requested' => $requested, 'statuses' => $statuses, 'courier_calls' => count($seen), 'queue_workers' => $workers];
}

// ---------------------------------------------------------------- deadlocks + report
if ($driver === 'mysql') {
    // Server-wide counter: every deadlock during the run (each one retried — or it would have
    // shown up as an error above). Reported, not gated: a retried deadlock is correct, only wasteful.
    $report['innodb_deadlocks_during_run'] = $innodbDeadlocks() - (int) $deadlocksBefore;
    $say("\nInnoDB deadlocks during the run (all retried): ".$report['innodb_deadlocks_during_run']);
}
$serverErrors = (int) shell_exec('grep -c "\\.ERROR" '.escapeshellarg($serverLog).' 2>/dev/null');
$report['server_log_errors'] = $serverErrors;
$check('server', 'no ERROR lines in the server log', $serverErrors === 0, $serverLog);
$report['failures'] = $failures;
if ($cfg['report'] !== null) {
    file_put_contents($cfg['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
if ($failures === []) {
    exec('rm -rf '.escapeshellarg($tmp)); // keep the server log and cookie jars only when something failed
}
$say($failures === [] ? "\nPASS — all scenarios" : "\nFAIL — ".count($failures)." check(s):\n  ".implode("\n  ", $failures));
exit($failures === [] ? 0 : 1);
