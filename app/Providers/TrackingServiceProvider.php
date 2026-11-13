<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\RefundPaid;
use App\Domain\Tracking\Listeners\RecordPurchaseConversion;
use App\Domain\Tracking\Listeners\RecordRefundConversion;
use App\Domain\Tracking\Services\TrackingDestinations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Server-side tracking wiring (PRD K-01..K-03, docs/api/tracking.md): destination drivers
 * by key (rule 5), the order-event listeners that emit `purchase` / `refund`, and the
 * ingest endpoint's rate limit.
 */
final class TrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $destinations = config('tracking.destinations', []);
        foreach (is_array($destinations) ? $destinations : [] as $key => $class) {
            if (is_string($key) && is_string($class) && class_exists($class)) {
                $this->app->bind(TrackingDestinations::binding($key), static fn (Application $app): object => $app->make($class));
            }
        }
    }

    public function boot(): void
    {
        // Exactly one of these fires `purchase` — the listener checks purchaseTrigger().
        Event::listen(OrderPlaced::class, RecordPurchaseConversion::class);
        Event::listen(OrderDelivered::class, RecordPurchaseConversion::class);
        Event::listen(RefundPaid::class, RecordRefundConversion::class);

        // POST /storefront/tracking/events: the Next.js server calls with one token for every
        // shopper, so the bucket is the shopper's IP it forwards in `client.ip` (falls back to
        // the request IP), per token.
        RateLimiter::for('tracking-events', static function (Request $request): Limit {
            $client = $request->input('client.ip');
            $ip = is_string($client) && filter_var($client, FILTER_VALIDATE_IP) !== false ? $client : $request->ip();
            $token = $request->user()?->currentAccessToken();
            $tokenKey = is_object($token) && isset($token->id) ? (string) $token->id : 'anon';

            return Limit::perMinute((int) config('tracking.ingest_per_minute', 120))->by('tracking:'.$tokenKey.'|'.$ip);
        });
    }
}
