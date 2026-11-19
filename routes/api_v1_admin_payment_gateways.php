<?php

declare(strict_types=1);

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
    // Filled by the payment-gateway settings work.
});
