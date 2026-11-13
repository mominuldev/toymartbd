<?php

declare(strict_types=1);

/*
 * Phase 9 endpoint profiler (docs/audits/PERFORMANCE.md). Runs the heaviest storefront and admin
 * GET endpoints in-process against whatever database the environment points at, and prints, per
 * endpoint: HTTP status, wall time (median of N warm runs), query count, total SQL time and the
 * slowest statement. With --explain it also prints SQLite's EXPLAIN QUERY PLAN for each endpoint's
 * slowest statement (MySQL plans have to be reasoned about separately — see the audit).
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=/tmp/vol.sqlite CACHE_STORE=array SCOUT_DRIVER=null \
 *     php scripts/perf/profile.php [--runs=3] [--explain] [--only=orders]
 *
 * Read-only by intent: every request is a GET. Never point it at a database you care about anyway.
 */

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($app->isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

// N+1 guard on real volume: every lazy load on a model from a multi-row result is recorded per endpoint.
$lazy = [];
Illuminate\Database\Eloquent\Model::preventLazyLoading();
Illuminate\Database\Eloquent\Model::handleLazyLoadingViolationUsing(static function ($model, string $relation) use (&$lazy): void {
    $lazy[$model::class.'::'.$relation] = true;
});

$opts = getopt('', ['runs::', 'explain', 'only::']);
$runs = max(1, (int) ($opts['runs'] ?? 3));
$explain = array_key_exists('explain', $opts);
$only = $opts['only'] ?? null;

$owner = User::query()->whereHas('roles', static fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
$token = $owner->createToken('perf-profile', App\Console\Commands\IssueStorefrontToken::ABILITIES)->plainTextToken;
DB::table('personal_access_tokens')->where('name', 'perf-profile')->where('id', '!=', explode('|', $token)[0])->delete();

$product = DB::table('products')->where('status', 'active')->orderByDesc('rating_count')->value('slug');
$category = DB::table('categories')->whereNull('parent_id')->value('slug');
$leaf = DB::table('categories')->whereNotNull('parent_id')->value('slug');
$brand = DB::table('brands')->value('slug');
$order = DB::table('orders')->orderByDesc('id')->value('id');
$customer = DB::table('customers')->orderByDesc('orders_count')->value('id');
$today = now('Asia/Dhaka')->toDateString();
$yearAgo = now('Asia/Dhaka')->subDays(364)->toDateString();

$store = [
    'sf products (listing p1)' => '/api/v1/storefront/products',
    'sf products (category)' => "/api/v1/storefront/products?filter[category]={$category}",
    'sf products (leaf, age, sort price)' => "/api/v1/storefront/products?filter[category]={$leaf}&filter[age]=36&sort=price_asc",
    'sf products (brand)' => "/api/v1/storefront/products?filter[brand]={$brand}",
    'sf search fallback' => '/api/v1/storefront/search?q=volume',
    'sf product page' => "/api/v1/storefront/products/{$product}",
    'sf product reviews' => "/api/v1/storefront/products/{$product}/reviews",
    'sf category tree' => '/api/v1/storefront/categories/tree',
    'sf category page' => "/api/v1/storefront/categories/{$category}",
    'sf menus' => '/api/v1/storefront/menus',
    'sf home content' => '/api/v1/storefront/content/home?locale=en',
    'sf flash sales' => '/api/v1/storefront/flash-sales',
];
$admin = [
    'admin dashboard (7d)' => '/api/v1/admin/dashboard',
    'admin dashboard (30d)' => '/api/v1/admin/dashboard?range=30d',
    'admin orders list' => '/api/v1/admin/orders',
    'admin orders (status=delivered)' => '/api/v1/admin/orders?filter[status]=delivered',
    'admin orders (search phone)' => '/api/v1/admin/orders?filter[search]=01710001234',
    'admin order show' => "/api/v1/admin/orders/{$order}",
    'admin customers list' => '/api/v1/admin/customers',
    'admin customers (sort spent)' => '/api/v1/admin/customers?sort=-lifetime_value',
    'admin customer show' => "/api/v1/admin/customers/{$customer}",
    'admin products list' => '/api/v1/admin/products',
    'admin sales analytics (30d)' => '/api/v1/admin/analytics/sales?range=30d',
    'admin sales analytics (365d)' => "/api/v1/admin/analytics/sales?filter[from]={$yearAgo}&filter[to]={$today}&group=month",
    'admin customer analytics' => '/api/v1/admin/reports/customers',
    'admin P&L (365d)' => "/api/v1/admin/finance/reports/pnl?filter[from]={$yearAgo}&filter[to]={$today}",
    'admin P&L series (365d)' => "/api/v1/admin/finance/reports/pnl/series?filter[from]={$yearAgo}&filter[to]={$today}&group=month",
    'admin profitability (365d)' => "/api/v1/admin/finance/reports/profitability?filter[from]={$yearAgo}&filter[to]={$today}",
    'admin ledger' => '/api/v1/admin/finance/ledger',
    'admin receivables' => '/api/v1/admin/finance/receivables',
    'admin courier report' => '/api/v1/admin/reports/couriers',
    'admin inventory valuation' => '/api/v1/admin/reports/inventory-valuation',
    'admin invoices list' => '/api/v1/admin/invoices',
    'admin risk queue' => '/api/v1/admin/risk/queue',
    'admin stock levels' => '/api/v1/admin/inventory/stock',
];

$rows = [];
foreach (['store' => $store, 'admin' => $admin] as $kind => $endpoints) {
    foreach ($endpoints as $label => $uri) {
        if ($only !== null && ! str_contains($label, (string) $only)) {
            continue;
        }
        $times = [];
        $queries = [];
        $lazy = [];
        $status = 0;
        for ($i = 0; $i <= $runs; $i++) { // run 0 warms the opcache/autoloader and is discarded
            $queries = [];
            DB::flushQueryLog();
            DB::listen(static function ($q) use (&$queries): void {
                $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
            });
            $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'en']);
            if ($kind === 'store') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
            Auth::forgetGuards();
            if ($kind === 'admin') {
                Auth::guard('web')->setUser($owner);
            }
            $t = hrtime(true);
            $response = $kernel->handle($request);
            $elapsed = (hrtime(true) - $t) / 1e6;
            $kernel->terminate($request, $response);
            $status = $response->getStatusCode();
            if ($i > 0) {
                $times[] = $elapsed;
            }
            $app['events']->forget(Illuminate\Database\Events\QueryExecuted::class);
        }
        sort($times);
        usort($queries, static fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $slow = $queries[0] ?? null;
        $rows[] = [
            'label' => $label, 'status' => $status, 'ms' => $times[intdiv(count($times), 2)], 'q' => count($queries),
            'sql_ms' => array_sum(array_column($queries, 'ms')),
            'slow' => $slow === null ? '' : sprintf('%.1fms %s', $slow['ms'], mb_strimwidth(preg_replace('/\s+/', ' ', $slow['sql']) ?? '', 0, 140, '…')),
            'slow_q' => $slow,
            'lazy' => implode(', ', array_keys($lazy)),
            'dupes' => count($queries) - count(array_unique(array_map(static fn ($q) => $q['sql'], $queries))),
        ];
    }
}

printf("| Endpoint | HTTP | ms (median of %d) | queries | repeated SQL | SQL ms | lazy loads | slowest statement |\n|---|---|---|---|---|---|---|---|\n", $runs);
foreach ($rows as $r) {
    printf("| %s | %d | %.0f | %d | %d | %.0f | %s | %s |\n", $r['label'], $r['status'], $r['ms'], $r['q'], $r['dupes'], $r['sql_ms'], $r['lazy'] === '' ? '0' : $r['lazy'], str_replace('|', '\\|', $r['slow']));
}

if ($explain) {
    foreach ($rows as $r) {
        if ($r['slow_q'] === null || $r['slow_q']['ms'] < 5) {
            continue;
        }
        echo "\n### {$r['label']} — {$r['slow_q']['ms']}ms\n".$r['slow_q']['sql']."\n";
        try {
            foreach (DB::select('EXPLAIN QUERY PLAN '.$r['slow_q']['sql'], $r['slow_q']['bindings']) as $p) {
                echo '  '.$p->detail."\n";
            }
        } catch (Throwable $e) {
            echo '  (explain failed: '.$e->getMessage().")\n";
        }
    }
}

DB::table('personal_access_tokens')->where('name', 'perf-profile')->delete();
