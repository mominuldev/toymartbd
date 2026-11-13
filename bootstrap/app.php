<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Problems\ProblemDetails;
use App\Providers\ApiServiceProvider;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    // API rate limiters. Registered here rather than in bootstrap/providers.php so the
    // API foundation owns its own wiring.
    ->withProviders([ApiServiceProvider::class])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API v1 is split by audience, not by resource. Each group gets its own
            // middleware stack, rate limits and auth model — see docs/SYSTEM-ARCHITECTURE.md §5.
            Route::middleware(['api', 'throttle:storefront'])
                ->prefix('api/v1/storefront')
                ->name('api.v1.storefront.')
                ->group(base_path('routes/api_v1_storefront.php'));

            // Sanctum's stateful stack (cookies, session, CSRF) on the admin group only, and
            // only for requests from a SANCTUM_STATEFUL_DOMAINS origin (P3-API-05).
            Route::middleware(['api', EnsureFrontendRequestsAreStateful::class, 'throttle:admin'])
                ->prefix('api/v1/admin')
                ->name('api.v1.admin.')
                ->group(base_path('routes/api_v1_admin.php'));

            // Webhooks are unauthenticated by design and verify a provider signature
            // BEFORE parsing or writing anything. They are exempt from throttling but
            // guarded by idempotency on the provider's event id.
            Route::middleware('api')
                ->prefix('api/v1/webhooks')
                ->name('api.v1.webhooks.')
                ->group(base_path('routes/api_v1_webhooks.php'));

            // One public health endpoint for the load balancer and uptime checks (§11).
            Route::middleware(['api', 'throttle:health'])
                ->get('api/v1/health', HealthController::class)
                ->name('api.v1.health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // X-Request-Id first, so every later log line, job and Sentry event carries it (P3-CI-04).
        $middleware->prepend(AssignRequestId::class);

        // nosniff, X-Frame-Options, HSTS over HTTPS, a locked-down CSP on the JSON API and a
        // nonce CSP on the admin shell (P9 security review).
        $middleware->append(SecurityHeaders::class);

        // Trusted proxies come from config/trustedproxy.php (env TRUSTED_PROXIES, default none,
        // wildcards refused). Only the client IP and scheme are taken from a trusted proxy —
        // never X-Forwarded-Host/Port/Prefix, which would let it rewrite generated URLs.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // Idempotency-Key guard for checkout, shipment booking and payment capture
        // (CLAUDE.md rule 17). List it AFTER any auth middleware: the key is scoped by user.
        $middleware->alias(['idempotent' => EnsureIdempotency::class]);

        // Locale from ?locale=, then Accept-Language (CLAUDE.md rule 35). Runs on every
        // API request; the staff-preference step needs the user, so it is prioritised
        // after authentication.
        $middleware->appendToGroup('api', ResolveLocale::class);
        // Extend Laravel's default priority list — never replace it with priority([...]),
        // which would drop the ordering of StartSession, SubstituteBindings, Authorize…
        // Sanctum's stateful stack must start the session before auth:sanctum reads it;
        // the locale's staff-preference step and the per-user idempotency scope both
        // need the authenticated user.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureFrontendRequestsAreStateful::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolveLocale::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureIdempotency::class);

        // An API guest gets a 401 `unauthenticated` problem, never a redirect, whatever
        // its Accept header. Web guests go to a `login` route once one exists.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') || ! Route::has('login') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reported exceptions go to Sentry (a no-op while SENTRY_LARAVEL_DSN is empty).
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Everything under api/* is an RFC 9457 problem (CLAUDE.md rule 18). Runs after
        // Laravel has normalised ModelNotFound/Authorization exceptions into HTTP ones.
        // An HttpResponseException already carries a deliberate response: leave it be.
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            return ProblemDetails::fromThrowable($e)->toResponse($request);
        });
    })->create();
