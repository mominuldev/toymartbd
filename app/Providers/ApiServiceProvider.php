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

    public const int ADMIN_PER_MINUTE = 300;

    public const int LOGIN_PER_MINUTE = 5;

    public const int OTP_PER_TEN_MINUTES = 3;

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
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute((int) config('auth.login_per_minute', self::LOGIN_PER_MINUTE))
            ->by('ip:'.$request->ip().'|phone:'.self::phoneKey($request)));

        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinutes(10, self::OTP_PER_TEN_MINUTES)
            ->by(($phone = self::phoneKey($request)) !== '' ? 'phone:'.$phone : 'ip:'.$request->ip()));

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
