<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CustomerAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\CustomerChildController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerNoteController;
use Illuminate\Support\Facades\Route;

/*
| Customers (P8, PRD U-01/U-02/U-05 read side; Figma B · 17 241:40845, B · 18 241:41374).
| Required from routes/api_v1_admin.php inside the auth:sanctum + EnsureActiveStaffSession
| group. Customers are never deleted. Notes are append-only.
*/

Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
Route::get('/customers/{customer}/notes', [CustomerNoteController::class, 'index'])->whereNumber('customer')->name('customers.notes.index');
Route::post('/customers/{customer}/notes', [CustomerNoteController::class, 'store'])->whereNumber('customer')->name('customers.notes.store');
// Children (P8 batch 3, birthday reminders) — customers.update + customers.view_pii; read on the
// profile (masked without view_pii). Consent: /notifications/customers/{customer}/consents.
Route::post('/customers/{customer}/children', [CustomerChildController::class, 'store'])->whereNumber('customer')->name('customers.children.store');
Route::patch('/customers/{customer}/children/{child}', [CustomerChildController::class, 'update'])->whereNumber(['customer', 'child'])->name('customers.children.update');
Route::delete('/customers/{customer}/children/{child}', [CustomerChildController::class, 'destroy'])->whereNumber(['customer', 'child'])->name('customers.children.destroy');

// Customer analytics (P8): LTV distribution, repeat rate, cohorts, segments, top customers.
Route::get('/reports/customers', [CustomerAnalyticsController::class, 'index'])->name('reports.customers');
