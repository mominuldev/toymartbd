<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * API rate limiters, per docs/SYSTEM-ARCHITECTURE.md §5 "Rate limiting".
 *
 * Exceeding any of them throws ThrottleRequestsException, which bootstrap/app.php
 * renders as a `too-many-requests` problem carrying `Retry-After`. The throttle
 * middleware md5-hashes every key, so a phone number never lands in the cache raw.
 * Webhooks are deliberately exempt (idempotency-guarded instead).
 */
class ApiServiceProvider extends ServiceProvider
{
    public const int STOREFRONT_PER_MINUTE = 120;

    public const int ADMIN_PER_MINUTE = 300;

    public const int LOGIN_PER_MINUTE = 5;

    public const int OTP_PER_TEN_MINUTES = 3;

    public const int CHECKOUT_PER_MINUTE = 10;

    public const int HEALTH_PER_MINUTE = 60;

    public function boot(): void
    {
        // Attached to the whole storefront group. Behind the Next.js server, $request->ip()
        // is only the shopper's IP if trusted proxies forward X-Forwarded-For.
        RateLimiter::for('storefront', fn (Request $request): Limit => Limit::perMinute(self::STOREFRONT_PER_MINUTE)
            ->by('ip:'.$request->ip()));

        // Attached to the whole admin group; per signed-in user, per IP before login.
        RateLimiter::for('admin', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(self::ADMIN_PER_MINUTE)
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });

        // Route-level limiters, attached by the endpoints that need them (Phase 3 auth, Phase 4 checkout).
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_PER_MINUTE)
            ->by('ip:'.$request->ip().'|phone:'.self::phoneKey($request)));

        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinutes(10, self::OTP_PER_TEN_MINUTES)
            ->by(($phone = self::phoneKey($request)) !== '' ? 'phone:'.$phone : 'ip:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(self::CHECKOUT_PER_MINUTE)
            ->by(($cart = self::cartKey($request)) !== '' ? 'cart:'.$cart : 'ip:'.$request->ip()));

        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute(self::HEALTH_PER_MINUTE)
            ->by('ip:'.$request->ip()));
    }

    /** Digits only, so "+8801712…" and "01712…" collapse to the same bucket suffix. */
    private static function phoneKey(Request $request): string
    {
        $digits = preg_replace('/\D+/', '', $request->string('phone')->toString()) ?? '';

        return substr($digits, -10);
    }

    /**
     * The cart identifier is not designed yet (Phase 4). Accept a `{cart}` route
     * parameter, an `X-Cart-Token` header or a `cart_token` field, in that order.
     */
    private static function cartKey(Request $request): string
    {
        $cart = $request->route('cart') ?? $request->header('X-Cart-Token') ?? $request->input('cart_token');

        if ($cart instanceof UrlRoutable) {
            $cart = $cart->getRouteKey();
        }

        return is_scalar($cart) ? (string) $cart : '';
    }
}
