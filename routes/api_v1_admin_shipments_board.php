<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shipments & tracking (Figma B · 08): list, stats, detail timeline, delivery issues.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
*/

$board = App\Http\Controllers\Api\V1\Admin\ShipmentsBoardController::class;
Route::get('/shipments/board', [$board, 'index'])->name('shipments.board.index');
Route::get('/shipments/board/stats', [$board, 'stats'])->name('shipments.board.stats');
Route::get('/shipments/board/issues', [$board, 'issues'])->name('shipments.board.issues');
Route::get('/shipments/{shipment}/tracking', [$board, 'tracking'])->whereNumber('shipment')->name('shipments.tracking');
