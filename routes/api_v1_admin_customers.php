<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CustomerAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerDateOfBirthController;
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
// Date of birth (P8 birthday messages) — customers.update + customers.view_pii. Consent: /notifications/customers/{customer}/consents.
Route::put('/customers/{customer}/date-of-birth', [CustomerDateOfBirthController::class, 'update'])->whereNumber('customer')->name('customers.date-of-birth.update');

// Customer analytics (P8): LTV distribution, repeat rate, cohorts, segments, top customers.
Route::get('/reports/customers', [CustomerAnalyticsController::class, 'index'])->name('reports.customers');
