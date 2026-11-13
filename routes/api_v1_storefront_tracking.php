<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\TrackingController;
use Illuminate\Support\Facades\Route;

/*
| Server-side tracking (P7 batch 2, PRD K-01..K-03) — docs/api/tracking.md §2.
| Required from routes/api_v1_storefront.php inside the storefront:read token group.
*/

Route::get('/tracking/config', [TrackingController::class, 'config'])->name('tracking.config');
Route::post('/tracking/events', [TrackingController::class, 'store'])->middleware('throttle:tracking-events')->name('tracking.events.store');
