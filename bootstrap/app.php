<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API v1 is split by audience, not by resource. Each group gets its own
            // middleware stack, rate limits and auth model — see docs/SYSTEM-ARCHITECTURE.md §5.
            Route::middleware('api')
                ->prefix('api/v1/storefront')
                ->name('api.v1.storefront.')
                ->group(base_path('routes/api_v1_storefront.php'));

            Route::middleware('api')
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
