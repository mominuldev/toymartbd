<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureIdempotency;
use App\Models\Enums\Permission;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
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
 *
 * Also wires the OpenAPI 3.1 document (P3-API-07, dedoc/scramble — see config/scramble.php).
 */
class ApiServiceProvider extends ServiceProvider
{
    public const int STOREFRONT_PER_MINUTE = 120;

    /**
     * The Next.js server's own cached (ISR) reads, which carry no shopper IP: every cache miss on
     * the site shares this one bucket, so it is sized for the whole shop (40/s), still bounded.
     */
    public const int STOREFRONT_SERVER_PER_MINUTE = 2400;

    public const int ADMIN_PER_MINUTE = 300;

    public const int LOGIN_PER_MINUTE = 5;

    /**
     * Admin sign-in attempts per account (email) per 15 minutes, from any IP (P9 security
     * review): the per-IP limit alone lets a botnet spread guesses at one staff account.
     */
    public const int LOGIN_PER_ACCOUNT_PER_FIFTEEN_MINUTES = 10;

    public const int TWO_FACTOR_PER_MINUTE = 5;

    public const int TWO_FACTOR_PER_ACCOUNT_PER_FIFTEEN_MINUTES = 15;

    /** POST /storefront/cart/coupon: tries per cart per 10 minutes (coupon-code guessing, P9). */
    public const int COUPON_PER_CART_PER_TEN_MINUTES = 10;

    /** …and per shopper IP per 10 minutes, generous for carrier-grade NAT. */
    public const int COUPON_PER_IP_PER_TEN_MINUTES = 60;

    /**
     * PUT /storefront/cart/contact per shopper IP per hour (P9): each contact can later receive a
     * cart-recovery SMS, so an unthrottled endpoint is an SMS-pumping lever. Generous for CGNAT.
     */
    public const int CART_CONTACT_PER_IP_PER_HOUR = 60;

    public const int OTP_PER_TEN_MINUTES = 3;

    /**
     * Sign-in codes requested per shopper IP per hour (P4-STO-11). The Next.js server forwards
     * the shopper's IP and Laravel trusts it only from TRUSTED_PROXIES (config/trustedproxy.php),
     * so this is a real per-client cap. Not lower: Bangladeshi mobile carriers put many
     * subscribers behind one carrier-grade NAT address. The per-phone limits stay the main guard.
     */
    public const int OTP_PER_IP_PER_HOUR = 30;

    /** Code checks per phone per 10 minutes, on top of the 5-guesses-per-code burn. */
    public const int OTP_VERIFY_PER_TEN_MINUTES = 10;

    public const int CHECKOUT_PER_MINUTE = 10;

    public const int HEALTH_PER_MINUTE = 60;

    /** Header autocomplete: a debounced typist sends a few a second at most (spec §A6.1: 250ms). */
    public const int SEARCH_SUGGEST_PER_MINUTE = 60;

    /** POST /storefront/orders/track (B7.1): attempts per 10 minutes per client + order number. */
    public const int ORDER_TRACK_PER_TEN_MINUTES = 5;

    /** …and per order number from anywhere, so spreading guesses over clients does not help. */
    public const int ORDER_TRACK_PER_NUMBER_PER_TEN_MINUTES = 20;

    /** GET /storefront/orders/{number} (confirmation page, cart token required). */
    public const int ORDER_LOOKUP_PER_MINUTE = 30;

    /** POST /storefront/account/reviews (P4-CAT-12): per customer per hour. */
    public const int REVIEWS_PER_HOUR = 10;

    public function boot(): void
    {
        // Attached to the whole storefront group. Behind the Next.js server, $request->ip() is
        // the shopper's IP only because that server is in TRUSTED_PROXIES (config/trustedproxy.php).
        // The server's own ISR reads (lib/api.ts `cached()`) carry NO shopper IP, by design (one
        // cache entry for everyone): from a trusted proxy without X-Forwarded-For they get the
        // server budget, not one shopper's 120/min — otherwise a crawler walking listing URLs (or a
        // revalidation burst) 429s every cache miss and the whole shop serves 500s (P9 SEO audit).
        RateLimiter::for('storefront', function (Request $request): Limit {
            // GET/HEAD only (P9 security review): an ISR read is always a GET, so a shopper write
            // that reaches us without a forwarded IP (Next could not determine one) stays on the
            // 120/min path instead of borrowing the whole-shop budget.
            if ($request->isMethodSafe() && $request->isFromTrustedProxy() && ! $request->headers->has('X-Forwarded-For')) {
                return Limit::perMinute(self::STOREFRONT_SERVER_PER_MINUTE)->by('storefront-server:'.$request->server->getString('REMOTE_ADDR'));
            }

            return Limit::perMinute(self::STOREFRONT_PER_MINUTE)->by('ip:'.$request->ip());
        });

        // Attached to the whole admin group; per signed-in user, per IP before login.
        RateLimiter::for('admin', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(self::ADMIN_PER_MINUTE)
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });

        // Route-level limiters, attached by the endpoints that need them (Phase 3 auth, Phase 4 checkout).
        RateLimiter::for('login', function (Request $request): array {
            $limits = [Limit::perMinute((int) config('auth.login_per_minute', self::LOGIN_PER_MINUTE))
                ->by('ip:'.$request->ip().'|phone:'.self::phoneKey($request))];

            $email = mb_strtolower(trim($request->string('email')->toString()));
            if ($email !== '') {
                $limits[] = Limit::perMinutes(15, (int) config('auth.login_per_account_per_fifteen_minutes', self::LOGIN_PER_ACCOUNT_PER_FIFTEEN_MINUTES))->by('login-account:'.hash('sha256', $email));
            }

            return $limits;
        });

        // Admin two-factor code (sign-in challenge and enrolment): per session, and per staff
        // account across sessions, so 10⁶ codes cannot be walked even from many browsers.
        RateLimiter::for('two-factor', function (Request $request): array {
            $pending = $request->hasSession() ? $request->session()->get(\App\Http\Controllers\Api\V1\Admin\Auth\AuthController::TWO_FACTOR_PENDING) : null;
            $userId = is_array($pending) ? ($pending['id'] ?? null) : $request->user()?->getAuthIdentifier();
            $session = $request->hasSession() ? $request->session()->getId() : (string) $request->ip();

            return [
                Limit::perMinute(self::TWO_FACTOR_PER_MINUTE)->by('2fa-session:'.$session),
                Limit::perMinutes(15, self::TWO_FACTOR_PER_ACCOUNT_PER_FIFTEEN_MINUTES)->by('2fa-account:'.(is_scalar($userId) ? (string) $userId : $session)),
            ];
        });

        RateLimiter::for('coupon', fn (Request $request): array => [
            Limit::perMinutes(10, self::COUPON_PER_CART_PER_TEN_MINUTES)->by('coupon-cart:'.self::cartKey($request)),
            Limit::perMinutes(10, self::COUPON_PER_IP_PER_TEN_MINUTES)->by('coupon-ip:'.$request->ip()),
        ]);

        RateLimiter::for('cart-contact', fn (Request $request): Limit => Limit::perHour(self::CART_CONTACT_PER_IP_PER_HOUR)
            ->by('cart-contact-ip:'.$request->ip()));

        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinutes(10, self::OTP_PER_TEN_MINUTES)
            ->by(($phone = self::phoneKey($request)) !== '' ? 'phone:'.$phone : 'ip:'.$request->ip()));

        RateLimiter::for('otp-ip', fn (Request $request): Limit => Limit::perHour(self::OTP_PER_IP_PER_HOUR)
            ->by('otp-ip:'.$request->ip()));

        RateLimiter::for('otp-verify', fn (Request $request): Limit => Limit::perMinutes(10, self::OTP_VERIFY_PER_TEN_MINUTES)
            ->by(($phone = self::phoneKey($request)) !== '' ? 'otp-verify:'.$phone : 'otp-verify-ip:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(self::CHECKOUT_PER_MINUTE)
            ->by(($cart = self::cartKey($request)) !== '' ? 'cart:'.$cart : 'ip:'.$request->ip()));

        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute(self::HEALTH_PER_MINUTE)
            ->by('ip:'.$request->ip()));

        // GET /storefront/search/suggest (P4-CAT-14), on top of the storefront group's limit.
        RateLimiter::for('search-suggest', fn (Request $request): Limit => Limit::perMinute(self::SEARCH_SUGGEST_PER_MINUTE)
            ->by('ip:'.$request->ip()));

        // Order tracking by number + phone digits (rule 22, P4-STO-10): a 4-digit guess space
        // is only safe with few attempts per order number.
        RateLimiter::for('order-track', fn (Request $request): array => [
            Limit::perMinutes(10, self::ORDER_TRACK_PER_TEN_MINUTES)->by('ip:'.$request->ip().'|order:'.self::orderKey($request)),
            Limit::perMinutes(10, self::ORDER_TRACK_PER_NUMBER_PER_TEN_MINUTES)->by('order:'.self::orderKey($request)),
        ]);

        RateLimiter::for('order-lookup', fn (Request $request): Limit => Limit::perMinute(self::ORDER_LOOKUP_PER_MINUTE)
            ->by('ip:'.$request->ip()));

        RateLimiter::for('reviews', function (Request $request): Limit {
            $customer = $request->user('customer');

            return Limit::perHour(self::REVIEWS_PER_HOUR)
                ->by($customer !== null ? 'customer:'.$customer->getAuthIdentifier() : 'ip:'.$request->ip());
        });

        $this->configureOpenApi();
    }

    /** The order number's digits only, so "TMB-26-00042" and "26 00042" share a bucket. */
    private static function orderKey(Request $request): string
    {
        $number = $request->route('number') ?? $request->input('number');

        if (! is_string($number)) {
            return '';
        }
        // Bengali digits count as the same digits, or they would open a second bucket.
        $number = str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $number);

        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /**
     * OpenAPI 3.1 (P3-API-07). UI at /api/docs, JSON at /api/docs.json — open only when
     * APP_ENV=local; elsewhere Scramble's RestrictedDocsAccess consults `viewApiDocs`
     * (a signed-in staff user holding `settings.view`). Export: `php artisan scramble:export`.
     *
     * Everything set here must be deterministic, because tests/Feature/OpenApi compares a
     * fresh export with the committed docs/api/openapi.json.
     */
    private function configureOpenApi(): void
    {
        // Deferred until the Gate is first resolved: resolving it here, during boot, would
        // fix its state before AuthServiceProvider and spatie/laravel-permission hook in.
        $this->callAfterResolving(GateContract::class, static function (GateContract $gate): void {
            $gate->define('viewApiDocs', static fn (?User $user = null): bool => $user?->can(Permission::SettingsView->value) ?? false);
        });

        Scramble::configure()
            ->expose(ui: 'api/docs', document: 'api/docs.json')
            ->withOperationTransformers(static function (Operation $operation, RouteInfo $routeInfo): void {
                $operation->setTags([self::audienceTag($routeInfo->route->uri())]);

                $middleware = $routeInfo->route->gatherMiddleware();

                if (in_array('idempotent', $middleware, true) || in_array(EnsureIdempotency::class, $middleware, true)) {
                    $operation->setAttribute('idempotent', true);
                }
            })
            ->withDocumentTransformers(static function (OpenApi $openApi): void {
                self::documentProblemsAndIdempotency($openApi);
            });
    }

    /** One tag per audience — the API is split by audience, not resource (SYSTEM-ARCHITECTURE §5). */
    private static function audienceTag(string $uri): string
    {
        return match (preg_match('#^api/v1/(storefront|admin|webhooks)(/|$)#', $uri, $m) === 1 ? $m[1] : null) {
            'storefront' => 'Storefront',
            'admin' => 'Admin',
            'webhooks' => 'Webhooks',
            default => 'System',
        };
    }

    private static function documentProblemsAndIdempotency(OpenApi $openApi): void
    {
        // A relative server keeps the document identical on every machine (no APP_URL).
        $openApi->servers = [Server::make('/api/v1')->setDescription('This host')];

        $openApi->tags = [
            new Tag('Storefront', 'Next.js storefront server — static server token or customer Sanctum token.'),
            new Tag('Admin', 'Admin SPA — Sanctum cookie session, CSRF token on every write.'),
            new Tag('Webhooks', 'Courier and payment-gateway callbacks — signature-verified, idempotent on the provider event id.'),
            new Tag('System', 'Health and other infrastructure endpoints.'),
        ];

        $problem = $openApi->components->addSchema('ProblemDetails', Schema::fromType(self::problemSchema()));

        $openApi->components->parameters['IdempotencyKey'] = tap(new Parameter(EnsureIdempotency::HEADER, 'header'), static function (Parameter $p): void {
            $p->required = true;
            $p->description = 'Client-generated unique key (a UUID v4 is ideal), 8–255 printable ASCII characters. '
                .'Retrying with the same key and body replays the stored 2xx response for 24h with `Idempotent-Replayed: true`; '
                .'the same key with a different body is 422 `idempotency-key-reused`; while the first request runs, 409 `request-in-progress`.';
            $p->schema = Schema::fromType((new StringType)->setMin(8)->setMax(255)->pattern('^[\x21-\x7E]{8,255}$'));
        });
        $idempotencyKey = new Reference('parameters', 'IdempotencyKey', $openApi->components);

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                if ($operation->getAttribute('idempotent') === true) {
                    $operation->addParameters([$idempotencyKey]);

                    foreach ([400 => 'Idempotency-Key missing or invalid', 409 => 'A request with this key is still in progress', 422 => 'Key reused with a different body, or validation failed'] as $code => $description) {
                        if (! self::hasResponse($operation, $code)) {
                            $operation->addResponse(Response::make($code)->setDescription($description));
                        }
                    }
                }

                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Response && is_numeric($response->code) && (int) $response->code >= 400) {
                        $response->content = ['application/problem+json' => $problem];
                    }
                }
            }
        }

        foreach ($openApi->components->responses as $response) {
            if ($response instanceof Response && is_numeric($response->code) && (int) $response->code >= 400) {
                $response->content = ['application/problem+json' => $problem];
            }
        }
    }

    private static function hasResponse(Operation $operation, int $code): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === (string) $code) {
                return true;
            }
        }

        return false;
    }

    /** The RFC 9457 document rendered by App\Http\Problems\ProblemDetails. */
    private static function problemSchema(): ObjectType
    {
        $error = (new ObjectType)
            ->addProperty('field', (new StringType)->setDescription('Dot-notation field path, e.g. `items.0.qty`.'))
            ->addProperty('message', new StringType)
            ->setRequired(['field', 'message']);

        $type = (new ObjectType)
            ->addProperty('type', (new StringType)->format('uri')->setDescription('Stable machine-readable problem type, e.g. `https://toymartbd.com/problems/validation-error`. Branch on this.'))
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('detail', new StringType)
            ->addProperty('errors', (new ArrayType)->setItems($error)->setDescription('Present on `validation-error` only.'))
            ->setRequired(['type', 'title', 'status', 'detail']);

        $type->setDescription('RFC 9457 problem details, served as `application/problem+json`.');

        return $type;
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
