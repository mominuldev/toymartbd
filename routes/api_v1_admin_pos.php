<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Pos\PosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS register (Figma B · 33): drawer sessions, sales, payments, holds, receipts.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|
| pos.use works one's own drawer; pos.manage closes / reads anyone's (PosSessionPolicy).
| docs/api/pos.md.
*/

Route::get('/pos/context', [PosController::class, 'context'])->name('pos.context');
Route::get('/pos/customers', [PosController::class, 'customer'])->name('pos.customers.show');
Route::post('/pos/sessions', [PosController::class, 'open'])->name('pos.sessions.store');
Route::get('/pos/sessions/{session}', [PosController::class, 'show'])->whereNumber('session')->name('pos.sessions.show');
Route::post('/pos/sessions/{session}/close', [PosController::class, 'close'])->whereNumber('session')->name('pos.sessions.close');
Route::get('/pos/sessions/{session}/catalog', [PosController::class, 'catalog'])->whereNumber('session')->name('pos.sessions.catalog');
Route::post('/pos/sessions/{session}/quote', [PosController::class, 'quote'])->whereNumber('session')->name('pos.sessions.quote');
// A charge moves stock and money: Idempotency-Key required (rule 17).
Route::post('/pos/sessions/{session}/sales', [PosController::class, 'charge'])->whereNumber('session')->middleware('idempotent')->name('pos.sessions.sales.store');
Route::get('/pos/sessions/{session}/holds', [PosController::class, 'holds'])->whereNumber('session')->name('pos.sessions.holds.index');
Route::post('/pos/sessions/{session}/holds', [PosController::class, 'hold'])->whereNumber('session')->name('pos.sessions.holds.store');
Route::post('/pos/holds/{hold}/resume', [PosController::class, 'resume'])->whereNumber('hold')->name('pos.holds.resume');
Route::get('/pos/sales/{sale}/receipt', [PosController::class, 'receipt'])->whereNumber('sale')->name('pos.sales.receipt');
