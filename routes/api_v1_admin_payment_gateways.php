<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Settings\PaymentGatewaysController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations › Payments: online gateway settings (SSLCommerz, PayStation) — owner request 2026-11-19.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
*/

Route::group([], function (): void {
    // Read and write: settings.manage_integrations (PaymentProviderPolicy). Keys are write-only.
    Route::get('/settings/payment-gateways', [PaymentGatewaysController::class, 'index'])->name('settings.payment-gateways.index');
    // Any word here, so a guest gets the 401 before routing can 404; an unmanaged code is a 404.
    Route::put('/settings/payment-gateways/{code}', [PaymentGatewaysController::class, 'update'])
        ->where('code', '[a-z_]{1,30}')
        ->name('settings.payment-gateways.update');
    // Check connection: one read-only call with the current mode's keys; throttled like the couriers' check.
    Route::post('/settings/payment-gateways/{code}/check', [PaymentGatewaysController::class, 'check'])
        ->where('code', '[a-z_]{1,30}')
        ->middleware('throttle:6,1')
        ->name('settings.payment-gateways.check');
});
