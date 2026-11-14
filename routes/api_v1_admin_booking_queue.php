<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\BookingQueue\BookingQueueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Courier booking queue (Figma B · 07): ready-to-ship, submitted, booked today, failed, unmapped.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|
| Read-only, shipments.view (docs/api/booking-queue.md). Booking goes through the existing
| POST /orders/{order}/shipments and POST /shipments/bulk (Idempotency-Key, rule 17).
*/

Route::prefix('/shipments/booking-queue')->name('shipments.booking-queue.')->group(function (): void {
    Route::get('/', [BookingQueueController::class, 'summary'])->name('summary');
    Route::get('/ready', [BookingQueueController::class, 'ready'])->name('ready');
    Route::get('/shipments', [BookingQueueController::class, 'shipments'])->name('shipments');
    Route::get('/unmapped', [BookingQueueController::class, 'unmapped'])->name('unmapped');
});
