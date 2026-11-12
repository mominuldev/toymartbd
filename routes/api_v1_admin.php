<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\AttributeValueController;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\OrderNoteController;
use App\Http\Controllers\Api\V1\Admin\OrderWorkflowController;
use App\Http\Controllers\Api\V1\Admin\ProductComplianceController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductPricingController;
use App\Http\Controllers\Api\V1\Admin\ProductSeoController;
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

    // Catalog. DELETE archives (a brand is never hard-deleted; products reference it).
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::patch('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

    // Products: one document (fields, categories, variant matrix, relations) per PUT.
    // DELETE archives; variants and SKUs are never deleted.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');

    // Product form helpers (P4 batch 5): server-computed margin and SERP width for unsaved
    // edits, and the BSTI certificate PDF (private; served only as a download).
    Route::post('/products/margin-preview', [ProductPricingController::class, 'marginPreview'])->name('products.margin-preview');
    Route::post('/products/seo-measure', [ProductSeoController::class, 'measure'])->name('products.seo-measure');
    Route::get('/products/{product}/compliance/certificate', [ProductComplianceController::class, 'show'])->name('products.compliance.certificate.show');
    Route::post('/products/{product}/compliance/certificate', [ProductComplianceController::class, 'store'])->name('products.compliance.certificate.store');
    Route::delete('/products/{product}/compliance/certificate', [ProductComplianceController::class, 'destroy'])->name('products.compliance.certificate.destroy');

    // Product images (media pipeline, P4-CAT-07): upload is multipart; renditions are queued.
    Route::get('/products/{product}/images', [ProductImageController::class, 'index'])->name('products.images.index');
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::post('/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])->name('products.images.reorder');
    Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update'])->scopeBindings()->name('products.images.update');
    Route::post('/products/{product}/images/{image}/crop', [ProductImageController::class, 'crop'])->scopeBindings()->name('products.images.crop');
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->scopeBindings()->name('products.images.destroy');

    // Categories (nested set). DELETE archives; never hard-deleted.
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::post('/categories/{category}/move', [CategoryController::class, 'move'])->name('categories.move');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Product attributes and their values. DELETE refuses (409) while a variant uses a value.
    Route::get('/attributes', [AttributeController::class, 'index'])->name('attributes.index');
    Route::post('/attributes', [AttributeController::class, 'store'])->name('attributes.store');
    Route::get('/attributes/{attribute}', [AttributeController::class, 'show'])->name('attributes.show');
    Route::patch('/attributes/{attribute}', [AttributeController::class, 'update'])->name('attributes.update');
    Route::delete('/attributes/{attribute}', [AttributeController::class, 'destroy'])->name('attributes.destroy');
    Route::post('/attributes/{attribute}/values', [AttributeValueController::class, 'store'])->name('attributes.values.store');
    Route::post('/attributes/{attribute}/values/reorder', [AttributeValueController::class, 'reorder'])->name('attributes.values.reorder');
    Route::patch('/attributes/{attribute}/values/{value}', [AttributeValueController::class, 'update'])->scopeBindings()->name('attributes.values.update');
    Route::delete('/attributes/{attribute}/values/{value}', [AttributeValueController::class, 'destroy'])->scopeBindings()->name('attributes.values.destroy');

    // Inventory (06-admin-feature-checklist §2.7): overview, append-only ledger + reconciliation,
    // adjustments. An adjustment moves stock, so a double submit must replay, not apply twice.
    Route::get('/inventory/summary', [InventoryController::class, 'summary'])->name('inventory.summary');
    Route::get('/inventory/stock', [InventoryController::class, 'stock'])->name('inventory.stock');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::get('/inventory/movements/reconciliation', [InventoryController::class, 'reconciliation'])->name('inventory.movements.reconciliation');
    Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->middleware('idempotent')->name('inventory.adjustments.store');

    // Coupons (P4-CRT-04). DELETE deactivates; usages keep the per-customer limits.
    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::get('/coupons/{coupon}', [CouponController::class, 'show'])->name('coupons.show');
    Route::put('/coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

    // Orders (P4-ORD-04/05 read side). Never deleted (rule 12); PII masked without customers.view_pii.
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Order workflow (P4-ORD-04/05 write side): each move goes through TransitionOrder /
    // CancelOrder; a move the state machine refuses is a 409. Bulk confirm reports per order.
    Route::post('/orders/bulk/confirm', [OrderWorkflowController::class, 'bulkConfirm'])->name('orders.bulk.confirm');
    Route::post('/orders/{order}/confirm', [OrderWorkflowController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/hold', [OrderWorkflowController::class, 'hold'])->name('orders.hold');
    Route::post('/orders/{order}/release', [OrderWorkflowController::class, 'release'])->name('orders.release');
    Route::post('/orders/{order}/processing', [OrderWorkflowController::class, 'processing'])->name('orders.processing');
    Route::post('/orders/{order}/ready-to-ship', [OrderWorkflowController::class, 'readyToShip'])->name('orders.ready-to-ship');
    Route::post('/orders/{order}/cancel', [OrderWorkflowController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/notes', [OrderNoteController::class, 'store'])->name('orders.notes.store');
});
