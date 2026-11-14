<?php

declare(strict_types=1);

namespace App\Console\Commands\Monitoring;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The synthetic add-to-cart check (SYSTEM-ARCHITECTURE §11, every 15 minutes), over real HTTP
 * against the public storefront API — the same path the Next.js server takes:
 *
 *   1. GET  /storefront/products?filter[availability]=in_stock  (or the configured slug)
 *   2. GET  /storefront/products/{slug}        → an in-stock variant
 *   3. POST /storefront/cart/items             → a NEW throwaway cart, quantity 1
 *   4. GET  /storefront/cart                   → the line is there, priced by the API
 *   5. DELETE /storefront/cart/items/{id}      → the cart is left empty
 *
 * Safe in production: a cart holds no stock reservation (stock is reserved only by an order),
 * no order, payment, customer, contact or consent is ever created, so nothing reaches the
 * ledger, the reports, abandoned-cart recovery or GA4/Meta (those fire on orders only). The
 * empty cart expires and is removed by `toymart:carts:prune`. Every request is tagged
 * `User-Agent: ToyMartBD-Synthetic/1.0`, `X-Synthetic-Check: 1` and an `X-Request-Id`
 * starting `synthetic-`, so it can be filtered out of access logs and traffic analytics.
 *
 * Skipped (exit 0) without MONITORING_SYNTHETIC_TOKEN. The result is the `synthetic.cart`
 * heartbeat; a failure exits 1 and logs the failing step — never the token (rule 20).
 */
#[Signature('toymart:synthetic:cart')]
#[Description('Synthetic add-to-cart check against the public storefront API (no order, no stock change)')]
final class SyntheticCartCommand extends Command
{
    public const string USER_AGENT = 'ToyMartBD-Synthetic/1.0';

    public function handle(): int
    {
        $token = config('monitoring.synthetic.token');
        $base = rtrim((string) config('monitoring.synthetic.api_url'), '/');
        if (! is_string($token) || $token === '' || $base === '') {
            $this->components->warn('Synthetic check skipped: MONITORING_SYNTHETIC_TOKEN / MONITORING_SYNTHETIC_API_URL not set.');

            return self::SUCCESS;
        }

        $runId = 'synthetic-'.Str::uuid7()->toString();
        $http = Http::baseUrl($base.'/api/v1/storefront')
            ->withToken($token)
            ->acceptJson()
            ->timeout(max(1, (int) config('monitoring.synthetic.timeout_seconds', 10)))
            ->withHeaders(['User-Agent' => self::USER_AGENT, 'X-Synthetic-Check' => '1', 'X-Request-Id' => $runId]);
        $started = hrtime(true);
        $step = 'list products';

        try {
            $slug = config('monitoring.synthetic.product_slug');
            if (! is_string($slug) || $slug === '') {
                $slug = $this->expect($http->get('/products', ['filter' => ['availability' => 'in_stock']]), 200, $step)->json('data.0.slug');
                if (! is_string($slug)) {
                    throw new RuntimeException('no in-stock product listed');
                }
            }

            $step = 'read product';
            $variants = $this->expect($http->get('/products/'.rawurlencode($slug)), 200, $step)->json('variants');
            $variantId = null;
            foreach (is_array($variants) ? $variants : [] as $variant) {
                if (is_array($variant) && is_int($variant['id'] ?? null) && (int) ($variant['available'] ?? 0) > 0) {
                    $variantId = $variant['id'];

                    break;
                }
            }
            if ($variantId === null) {
                throw new RuntimeException('product has no in-stock variant');
            }

            $step = 'add to cart';
            $added = $this->expect($http->post('/cart/items', ['variant_id' => $variantId, 'quantity' => 1]), 201, $step);
            $cartToken = $added->json('token');
            $lineId = $added->json('items.0.id');
            if (! is_string($cartToken) || ! is_int($lineId)) {
                throw new RuntimeException('cart response has no token or line');
            }
            $cart = $http->withHeaders(['X-Cart-Token' => $cartToken]);

            $step = 'read cart';
            $read = $this->expect($cart->get('/cart'), 200, $step);
            if ((int) $read->json('item_count') < 1) {
                throw new RuntimeException('cart is empty after add');
            }

            $step = 'remove line';
            $this->expect($cart->delete('/cart/items/'.$lineId), 200, $step);
        } catch (Throwable $e) {
            $message = "{$step}: ".($e instanceof RuntimeException ? $e->getMessage() : $e::class);
            SystemHeartbeat::failed(SystemHeartbeat::SYNTHETIC_CART, $message);
            Log::error('monitoring.synthetic.cart_failed', ['step' => $step, 'error' => $message, 'request_id' => $runId]);
            $this->components->error('Synthetic add-to-cart FAILED at '.$message);

            return self::FAILURE;
        }

        $ms = (int) round((hrtime(true) - $started) / 1e6);
        SystemHeartbeat::beat(SystemHeartbeat::SYNTHETIC_CART, ['duration_ms' => $ms]);
        $this->components->info("Synthetic add-to-cart passed in {$ms} ms ({$runId}).");

        return self::SUCCESS;
    }

    private function expect(Response $response, int $status, string $step): Response
    {
        if ($response->status() !== $status) {
            throw new RuntimeException("HTTP {$response->status()} (expected {$status})");
        }

        return $response;
    }
}
