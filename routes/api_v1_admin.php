<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Middleware\EnsureActiveStaffSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API — v1
|--------------------------------------------------------------------------
|
| Consumed by the React admin SPA over a Sanctum cookie session, so no
| long-lived token sits in JavaScript where XSS could reach it. Every write
| requires a CSRF token (X-XSRF-TOKEN); every endpoint is guarded by a Policy
| or a `can:<permission>` middleware, and has a 403 role-matrix test
| (tests/Support/RoleMatrix.php).
|
| Every endpoint except `ping` and `auth/login` sits behind `auth:sanctum` plus
| EnsureActiveStaffSession: a bearer token (storefront, customer) is refused
| with 403, a deactivated account with 401.
|
*/

Route::get('/ping', fn () => ['ok' => true, 'audience' => 'admin'])->name('ping');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', EnsureActiveStaffSession::class])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
});
