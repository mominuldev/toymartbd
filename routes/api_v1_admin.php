<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\AttributeValueController;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\Admin\InventoryAlertController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\InventoryTransferController;
use App\Http\Controllers\Api\V1\Admin\MediaBulkController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\MediaFolderController;
use App\Http\Controllers\Api\V1\Admin\NotificationLogController;
use App\Http\Controllers\Api\V1\Admin\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\OrderComposerController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\OrderEditController;
use App\Http\Controllers\Api\V1\Admin\OrderFulfilmentController;
use App\Http\Controllers\Api\V1\Admin\OrderInvoiceController;
use App\Http\Controllers\Api\V1\Admin\OrderNoteController;
use App\Http\Controllers\Api\V1\Admin\OrderPackingController;
use App\Http\Controllers\Api\V1\Admin\OrderWorkflowController;
use App\Http\Controllers\Api\V1\Admin\ProductComplianceController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductExportController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductImportController;
use App\Http\Controllers\Api\V1\Admin\ProductPricingController;
use App\Http\Controllers\Api\V1\Admin\ProductSeoController;
use App\Http\Controllers\Api\V1\Admin\PurchaseController;
use App\Http\Controllers\Api\V1\Admin\PurchaseReceiptController;
use App\Http\Controllers\Api\V1\Admin\RefundController;
use App\Http\Controllers\Api\V1\Admin\ReturnController;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\SupplierPaymentController;
use App\Http\Middleware\EnsureActiveStaffSession;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
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

// Second step of a sign-in with two-factor on: the session (not a user) knows who is half-way.
Route::post('/auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])
    ->middleware('throttle:two-factor')
    ->name('auth.two-factor-challenge');

Route::middleware(['auth:sanctum', EnsureActiveStaffSession::class, EnsureTwoFactorEnrolled::class])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    // Your own two-factor sign-in (owner decision 2026-11-14). Reachable before enrolment.
    Route::get('/auth/two-factor', [TwoFactorController::class, 'show'])->name('auth.two-factor.show');
    Route::post('/auth/two-factor', [TwoFactorController::class, 'store'])->name('auth.two-factor.store');
    Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor')->name('auth.two-factor.confirm');
    Route::post('/auth/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:two-factor')->name('auth.two-factor.recovery-codes');
    Route::delete('/auth/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:two-factor')->name('auth.two-factor.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    // Staff & roles (B · 35). Writes need staff.manage; staff are deactivated, never deleted.
    Route::get('/staff/roles', [StaffController::class, 'roles'])->name('staff.roles');
    Route::put('/staff/roles/{role}', [StaffController::class, 'updateRole'])->where('role', '[a-z_]+')->name('staff.roles.update');
    Route::post('/staff/{user}/two-factor/reset', [StaffController::class, 'resetTwoFactor'])->whereNumber('user')->name('staff.two-factor.reset');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{user}', [StaffController::class, 'update'])->whereNumber('user')->name('staff.update');
    Route::post('/staff/{user}/deactivate', [StaffController::class, 'deactivate'])->whereNumber('user')->name('staff.deactivate');
    Route::post('/staff/{user}/reactivate', [StaffController::class, 'reactivate'])->whereNumber('user')->name('staff.reactivate');
    Route::post('/staff/{user}/password', [StaffController::class, 'resetPassword'])->whereNumber('user')->middleware('throttle:10,1')->name('staff.password');

    // Dashboard (P8): KPIs, status strip, trend, best sellers, low stock, needs attention. Read-only.
    Route::get('/dashboard', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'show'])->name('dashboard');
    Route::get('/dashboard/export', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'export'])->name('dashboard.export');

    // Catalog. DELETE archives (a brand is never hard-deleted; products reference it).
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
    Route::get('/brands/{brand}', [BrandController::class, 'show'])->name('brands.show');
    Route::patch('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

    // Products: one document (fields, categories, variant matrix, relations) per PUT.
    // DELETE archives; variants and SKUs are never deleted.
    // Bulk import/export (P4-CAT-10). The export is the list with its filters; the import
    // previews every row (dry run) before a separate commit. Before /products/{product}.
    Route::get('/products/export', ProductExportController::class)->name('products.export');
    Route::post('/product-imports', [ProductImportController::class, 'store'])->name('product-imports.store');
    Route::get('/product-imports/{import}', [ProductImportController::class, 'show'])->whereNumber('import')->name('product-imports.show');
    Route::post('/product-imports/{import}/commit', [ProductImportController::class, 'commit'])->whereNumber('import')->name('product-imports.commit');
    Route::get('/product-imports/{import}/errors', [ProductImportController::class, 'errors'])->whereNumber('import')->name('product-imports.errors');

    // Review moderation (P4-CAT-12). Approve/reject recompute the product's rating aggregates.
    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/reviews/{review}', [ReviewController::class, 'show'])->whereNumber('review')->name('reviews.show');
    Route::post('/reviews/{review}/approve', [ReviewController::class, 'approve'])->whereNumber('review')->name('reviews.approve');
    Route::post('/reviews/{review}/reject', [ReviewController::class, 'reject'])->whereNumber('review')->name('reviews.reject');
    Route::put('/reviews/{review}/reply', [ReviewController::class, 'reply'])->whereNumber('review')->name('reviews.reply');

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

    // SEO module (P7, docs/api/seo.md §2): per-entity panel, redirects, sitemap, robots.txt.
    Route::prefix('seo')->name('seo.')->group(function (): void {
        Route::get('/meta/{type}/{id}', [App\Http\Controllers\Api\V1\Admin\Seo\SeoMetaController::class, 'show'])->where('type', '[a-z_]{1,64}')->whereNumber('id')->name('meta.show');
        Route::put('/meta/{type}/{id}', [App\Http\Controllers\Api\V1\Admin\Seo\SeoMetaController::class, 'update'])->where('type', '[a-z_]{1,64}')->whereNumber('id')->name('meta.update');
        Route::post('/serp-preview', [App\Http\Controllers\Api\V1\Admin\Seo\SeoMetaController::class, 'preview'])->name('serp-preview');
        Route::get('/redirects', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'index'])->name('redirects.index');
        Route::post('/redirects', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'store'])->name('redirects.store');
        Route::post('/redirects/import', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'import'])->name('redirects.import');
        Route::patch('/redirects/{redirect}', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'update'])->whereNumber('redirect')->name('redirects.update');
        Route::delete('/redirects/{redirect}', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'destroy'])->whereNumber('redirect')->name('redirects.destroy');
        Route::get('/slug-history', [App\Http\Controllers\Api\V1\Admin\Seo\RedirectController::class, 'slugHistory'])->name('slug-history.index');
        Route::get('/sitemap', [App\Http\Controllers\Api\V1\Admin\Seo\SitemapController::class, 'show'])->name('sitemap.show');
        Route::post('/sitemap/regenerate', [App\Http\Controllers\Api\V1\Admin\Seo\SitemapController::class, 'regenerate'])->name('sitemap.regenerate');
        Route::get('/robots', [App\Http\Controllers\Api\V1\Admin\Seo\SitemapController::class, 'robots'])->name('robots.show');
        Route::put('/robots', [App\Http\Controllers\Api\V1\Admin\Seo\SitemapController::class, 'updateRobots'])->name('robots.update');
    });
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

    // Media library (docs/api/media-library.md). Static paths before {media}.
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::get('/media/counts', [MediaController::class, 'counts'])->name('media.counts');
    Route::get('/media/storage', [MediaController::class, 'storage'])->name('media.storage');
    Route::get('/media/folders', [MediaFolderController::class, 'index'])->name('media.folders.index');
    Route::post('/media/folders', [MediaFolderController::class, 'store'])->name('media.folders.store');
    Route::patch('/media/folders/{folder}', [MediaFolderController::class, 'update'])->name('media.folders.update');
    Route::delete('/media/folders/{folder}', [MediaFolderController::class, 'destroy'])->name('media.folders.destroy');
    Route::post('/media/bulk/move', [MediaBulkController::class, 'move'])->name('media.bulk.move');
    Route::post('/media/bulk/delete', [MediaBulkController::class, 'delete'])->name('media.bulk.delete');
    Route::post('/media/bulk/download', [MediaBulkController::class, 'download'])->name('media.bulk.download');
    Route::get('/media/{media}', [MediaController::class, 'show'])->whereNumber('media')->name('media.show');
    Route::patch('/media/{media}', [MediaController::class, 'update'])->whereNumber('media')->name('media.update');
    Route::post('/media/{media}/replace', [MediaController::class, 'replace'])->whereNumber('media')->name('media.replace');
    Route::get('/media/{media}/download', [MediaController::class, 'download'])->whereNumber('media')->name('media.download');
    Route::delete('/media/{media}', [MediaController::class, 'destroy'])->whereNumber('media')->name('media.destroy');

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
    Route::get('/inventory/low-stock-count', [InventoryController::class, 'lowStockCount'])->name('inventory.low-stock-count');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::get('/inventory/movements/reconciliation', [InventoryController::class, 'reconciliation'])->name('inventory.movements.reconciliation');
    Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->middleware('idempotent')->name('inventory.adjustments.store');
    // P5-INV: approval queue, transfers between locations, low-stock / drift alerts.
    Route::get('/inventory/adjustments', [InventoryAdjustmentController::class, 'index'])->name('inventory.adjustments.index');
    Route::get('/inventory/adjustments/{adjustment}', [InventoryAdjustmentController::class, 'show'])->whereNumber('adjustment')->name('inventory.adjustments.show');
    Route::post('/inventory/adjustments/{adjustment}/approve', [InventoryAdjustmentController::class, 'approve'])->whereNumber('adjustment')->middleware('idempotent')->name('inventory.adjustments.approve');
    Route::post('/inventory/adjustments/{adjustment}/reject', [InventoryAdjustmentController::class, 'reject'])->whereNumber('adjustment')->name('inventory.adjustments.reject');
    Route::get('/inventory/transfers', [InventoryTransferController::class, 'index'])->name('inventory.transfers.index');
    Route::post('/inventory/transfers', [InventoryTransferController::class, 'store'])->middleware('idempotent')->name('inventory.transfers.store');
    Route::get('/inventory/alerts', [InventoryAlertController::class, 'index'])->name('inventory.alerts.index');
    Route::post('/inventory/alerts/{alert}/acknowledge', [InventoryAlertController::class, 'acknowledge'])->whereNumber('alert')->name('inventory.alerts.acknowledge');

    // Purchasing (P5): suppliers, purchase orders, receipts with landed cost, supplier payments.
    // A receipt moves stock and a payment moves money, so both need an Idempotency-Key.
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
    Route::get('/suppliers/{supplier}/payments', [SupplierPaymentController::class, 'index'])->whereNumber('supplier')->name('suppliers.payments.index');
    Route::post('/suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->whereNumber('supplier')->middleware('idempotent')->name('suppliers.payments.store');
    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/counts', [PurchaseController::class, 'counts'])->name('purchases.counts');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
    Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase')->name('purchases.update');
    Route::post('/purchases/{purchase}/order', [PurchaseController::class, 'place'])->whereNumber('purchase')->name('purchases.order');
    Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase')->name('purchases.cancel');
    Route::post('/purchases/{purchase}/close', [PurchaseController::class, 'close'])->whereNumber('purchase')->name('purchases.close');
    Route::post('/purchases/{purchase}/receipts/quote', [PurchaseReceiptController::class, 'quote'])->whereNumber('purchase')->name('purchases.receipts.quote');
    Route::post('/purchases/{purchase}/receipts', [PurchaseReceiptController::class, 'store'])->whereNumber('purchase')->middleware('idempotent')->name('purchases.receipts.store');

    // Coupons (P4-CRT-04). DELETE deactivates; usages keep the per-customer limits.
    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::get('/coupons/{coupon}', [CouponController::class, 'show'])->name('coupons.show');
    Route::put('/coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');
    // Coupon builder (P7): bulk unique codes and the usage report.
    Route::post('/coupons/generate', [CouponController::class, 'generate'])->name('coupons.generate');
    Route::get('/coupons/{coupon}/report', [CouponController::class, 'report'])->name('coupons.report');

    // Promotions and flash sales (P7, docs/api/promotions.md). DELETE deactivates.
    // Builder lookups under marketing permissions (P7 follow-up): flash-sale variant picker and scope-chip names.
    Route::get('/marketing/lookups/variants', [App\Http\Controllers\Api\V1\Admin\MarketingLookupController::class, 'variants'])->name('marketing.lookups.variants');
    Route::get('/marketing/lookups/names', [App\Http\Controllers\Api\V1\Admin\MarketingLookupController::class, 'names'])->name('marketing.lookups.names');
    Route::get('/promotions', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'index'])->name('promotions.index');
    Route::post('/promotions', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'store'])->name('promotions.store');
    Route::get('/promotions/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'show'])->whereNumber('promotion')->name('promotions.show');
    Route::put('/promotions/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'update'])->whereNumber('promotion')->name('promotions.update');
    Route::delete('/promotions/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'destroy'])->whereNumber('promotion')->name('promotions.destroy');
    Route::get('/promotions/{promotion}/report', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'report'])->whereNumber('promotion')->name('promotions.report');

    // Abandoned carts (P7, docs/api/cart-recovery.md): read-only report.
    Route::get('/cart-recoveries', [App\Http\Controllers\Api\V1\Admin\CartRecoveryController::class, 'index'])->name('cart-recoveries.index');
    Route::get('/cart-recoveries/summary', [App\Http\Controllers\Api\V1\Admin\CartRecoveryController::class, 'summary'])->name('cart-recoveries.summary');

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
    // Re-query the gateway for an online payment (P4-PAY-03); needs orders.update.
    Route::post('/orders/{order}/payments/{payment}/verify', [App\Http\Controllers\Api\V1\Admin\OrderPaymentController::class, 'verify'])->middleware('idempotent')->whereNumber('payment')->name('orders.payments.verify');

    // Manual fulfilment (P5, until Phase 6 courier booking): ship consumes stock, deliver
    // recognises revenue (Finance listens) and collects COD. A double submit must replay.
    Route::post('/orders/bulk/ship', [OrderFulfilmentController::class, 'bulkShip'])->middleware('idempotent')->name('orders.bulk.ship');
    Route::post('/orders/bulk/deliver', [OrderFulfilmentController::class, 'bulkDeliver'])->middleware('idempotent')->name('orders.bulk.deliver');
    Route::post('/orders/{order}/ship', [OrderFulfilmentController::class, 'ship'])->middleware('idempotent')->name('orders.ship');
    Route::post('/orders/{order}/deliver', [OrderFulfilmentController::class, 'deliver'])->middleware('idempotent')->name('orders.deliver');

    // Courier booking (P6, docs/api/shipments.md): request → BookShipment on `courier` →
    // booked (order shipped) or failed (retry queue). Writes need an Idempotency-Key (rule 17).
    Route::post('/orders/{order}/shipments', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'store'])->middleware('idempotent')->name('orders.shipments.store');
    Route::post('/shipments/bulk', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'bulk'])->middleware('idempotent')->name('shipments.bulk');
    Route::get('/shipments', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('/shipments/failed', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'failed'])->name('shipments.failed');
    Route::get('/shipments/{shipment}', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'show'])->whereNumber('shipment')->name('shipments.show');
    Route::post('/shipments/{shipment}/retry', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'retry'])->whereNumber('shipment')->middleware('idempotent')->name('shipments.retry');
    Route::post('/shipments/{shipment}/cancel', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'cancel'])->whereNumber('shipment')->middleware('idempotent')->name('shipments.cancel');
    Route::patch('/shipments/{shipment}/rider', [App\Http\Controllers\Api\V1\Admin\ShipmentController::class, 'rider'])->whereNumber('shipment')->name('shipments.rider');

    // Manual orders (P4-ORD-06): lookups, a live quote and the order itself, all through the
    // checkout pricing pipeline and PlaceOrder. Edit after placement (P4-ORD-07). Bulk pack
    // and invoice PDFs (P4-ORD-08) report per order, never all-or-nothing.
    Route::get('/orders/compose/customer', [OrderComposerController::class, 'customer'])->name('orders.compose.customer');
    Route::get('/orders/compose/variants', [OrderComposerController::class, 'variants'])->name('orders.compose.variants');
    Route::get('/orders/compose/districts', [OrderComposerController::class, 'districts'])->name('orders.compose.districts');
    Route::get('/orders/compose/districts/{district}/upazilas', [OrderComposerController::class, 'upazilas'])->whereNumber('district')->name('orders.compose.upazilas');
    Route::post('/orders/quote', [OrderComposerController::class, 'quote'])->name('orders.quote');
    Route::post('/orders', [OrderComposerController::class, 'store'])->middleware('idempotent')->name('orders.store');
    Route::post('/orders/{order}/edit-quote', [OrderEditController::class, 'quote'])->name('orders.edit-quote');
    Route::put('/orders/{order}', [OrderEditController::class, 'update'])->name('orders.update');
    Route::post('/orders/bulk/pack', [OrderPackingController::class, 'bulkPack'])->name('orders.bulk.pack');
    Route::post('/orders/bulk/invoices', [OrderInvoiceController::class, 'bulk'])->name('orders.bulk.invoices');
    Route::get('/orders/{order}/invoice', [OrderInvoiceController::class, 'show'])->name('orders.invoice');

    // Invoices (P8, PRD N-13): issued on first print, gap-free INV-/PINV- numbers, immutable
    // company + line snapshot; reprints render the snapshot. Supplier invoice = a PO's PDF.
    Route::get('/invoices', [App\Http\Controllers\Api\V1\Admin\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [App\Http\Controllers\Api\V1\Admin\InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [App\Http\Controllers\Api\V1\Admin\InvoiceController::class, 'pdf'])->whereNumber('invoice')->name('invoices.pdf');
    Route::get('/purchases/{purchase}/invoice', [App\Http\Controllers\Api\V1\Admin\InvoiceController::class, 'purchase'])->whereNumber('purchase')->name('purchases.invoice');
    // Credit notes (P8 batch 2): issued by Invoicing's listeners on cancellation after issue
    // and on a paid refund; read and reprinted here only (immutable).
    Route::get('/credit-notes', [App\Http\Controllers\Api\V1\Admin\CreditNoteController::class, 'index'])->name('credit-notes.index');
    Route::get('/credit-notes/{creditNote}', [App\Http\Controllers\Api\V1\Admin\CreditNoteController::class, 'show'])->whereNumber('creditNote')->name('credit-notes.show');
    Route::get('/credit-notes/{creditNote}/pdf', [App\Http\Controllers\Api\V1\Admin\CreditNoteController::class, 'pdf'])->whereNumber('creditNote')->name('credit-notes.pdf');
    Route::get('/settings/invoice', [App\Http\Controllers\Api\V1\Admin\Settings\InvoiceSettingsController::class, 'show'])->name('settings.invoice.show');
    Route::put('/settings/invoice', [App\Http\Controllers\Api\V1\Admin\Settings\InvoiceSettingsController::class, 'update'])->name('settings.invoice.update');

    // Returns & refunds (DATABASE-DESIGN §4.7, PRD O-21, Figma 211:5121). Paying a refund out
    // moves money, so a double submit must replay, not pay twice.
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/{orderReturn}', [ReturnController::class, 'show'])->name('returns.show');
    Route::post('/returns/{orderReturn}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
    Route::post('/returns/{orderReturn}/inspect', [ReturnController::class, 'inspect'])->name('returns.inspect');
    Route::post('/returns/{orderReturn}/reject', [ReturnController::class, 'reject'])->name('returns.reject');
    Route::get('/orders/{order}/returns', [ReturnController::class, 'forOrder'])->name('orders.returns.index');
    Route::post('/orders/{order}/returns', [ReturnController::class, 'store'])->name('orders.returns.store');
    Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
    Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');
    Route::post('/refunds/{refund}/pay', [RefundController::class, 'pay'])->middleware('idempotent')->name('refunds.pay');

    // Notifications (P4-NOT-01..03): templates (EN + BN, server-measured segments), the
    // message log with per-message cost and dead letters, and customer opt-outs.
    Route::get('/notifications/templates', [NotificationTemplateController::class, 'index'])->name('notifications.templates.index');
    Route::get('/notifications/templates/{template}', [NotificationTemplateController::class, 'show'])->name('notifications.templates.show');
    Route::put('/notifications/templates/{template}', [NotificationTemplateController::class, 'update'])->name('notifications.templates.update');
    Route::post('/notifications/templates/{template}/preview', [NotificationTemplateController::class, 'preview'])->name('notifications.templates.preview');
    // Message templates library (P7): custom templates, archive, version history, WhatsApp approval state.
    Route::post('/notifications/templates', [NotificationTemplateController::class, 'store'])->name('notifications.templates.store');
    Route::post('/notifications/templates/{template}/archive', [NotificationTemplateController::class, 'archive'])->name('notifications.templates.archive');
    Route::post('/notifications/templates/{template}/unarchive', [NotificationTemplateController::class, 'unarchive'])->name('notifications.templates.unarchive');
    Route::get('/notifications/templates/{template}/versions', [NotificationTemplateController::class, 'versions'])->name('notifications.templates.versions');
    Route::post('/notifications/templates/{template}/versions/{version}/restore', [NotificationTemplateController::class, 'restoreVersion'])->whereNumber('version')->name('notifications.templates.versions.restore');
    Route::put('/notifications/templates/{template}/whatsapp-status', [NotificationTemplateController::class, 'whatsAppStatus'])->name('notifications.templates.whatsapp-status');
    Route::get('/notifications/logs', [NotificationLogController::class, 'index'])->name('notifications.logs.index');
    Route::get('/notifications/logs/summary', [NotificationLogController::class, 'summary'])->name('notifications.logs.summary');
    Route::post('/notifications/logs/{log}/retry', [NotificationLogController::class, 'retry'])->whereNumber('log')->name('notifications.logs.retry');
    Route::get('/notifications/customers/{customer}/preferences', [NotificationPreferenceController::class, 'show'])->name('notifications.preferences.show');
    Route::put('/notifications/customers/{customer}/preferences', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');
    // Scheduled automations (P8): review requests + birthday messages — scheduling values and
    // the birthday consent (on/off and copy are the templates above).
    Route::get('/notifications/automations', [App\Http\Controllers\Api\V1\Admin\AutomationController::class, 'show'])->name('notifications.automations.show');
    Route::put('/notifications/automations', [App\Http\Controllers\Api\V1\Admin\AutomationController::class, 'update'])->name('notifications.automations.update');
    Route::get('/notifications/customers/{customer}/consents', [App\Http\Controllers\Api\V1\Admin\AutomationController::class, 'consents'])->name('notifications.consents.show');
    Route::put('/notifications/customers/{customer}/consents', [App\Http\Controllers\Api\V1\Admin\AutomationController::class, 'updateConsents'])->name('notifications.consents.update');

    // Integrations → Messaging (owner request 2026-11-13): SMS credentials in the admin,
    // encrypted in `settings`, env as the fallback. Credentials page → manage_integrations
    // for everything; the test send is rate-limited per user.
    Route::middleware('can:'.App\Models\Enums\Permission::SettingsManageIntegrations->value)->group(function (): void {
        Route::get('/settings/messaging', [App\Http\Controllers\Api\V1\Admin\Settings\MessagingSettingsController::class, 'show'])->name('settings.messaging.show');
        Route::put('/settings/messaging', [App\Http\Controllers\Api\V1\Admin\Settings\MessagingSettingsController::class, 'update'])->name('settings.messaging.update');
        Route::post('/settings/messaging/test', [App\Http\Controllers\Api\V1\Admin\Settings\MessagingSettingsController::class, 'test'])->middleware('throttle:5,1')->name('settings.messaging.test');
    });

    // Integrations → Tracking & pixels (P7 server-side tracking, K-01..K-03). Settings and the
    // test event → settings.manage_integrations (credentials screen); diagnostics (event log,
    // one event, health summary) → settings.view, enforced by TrackingEventPolicy.
    Route::middleware('can:'.App\Models\Enums\Permission::SettingsManageIntegrations->value)->group(function (): void {
        Route::get('/settings/tracking', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingSettingsController::class, 'show'])->name('settings.tracking.show');
        Route::put('/settings/tracking', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingSettingsController::class, 'update'])->name('settings.tracking.update');
        Route::post('/settings/tracking/test', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingSettingsController::class, 'test'])->middleware('throttle:5,1')->name('settings.tracking.test');
    });
    Route::get('/tracking/events', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingEventsController::class, 'index'])->name('tracking.events.index');
    Route::get('/tracking/events/{event}', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingEventsController::class, 'show'])->whereNumber('event')->name('tracking.events.show');
    Route::get('/tracking/summary', [App\Http\Controllers\Api\V1\Admin\Settings\TrackingEventsController::class, 'summary'])->name('tracking.summary');

    // Courier settings & delivery charges (P6): rate cards / on-off / default / free-delivery
    // threshold → shipments.manage_rates; credentials (write-only) → settings.manage_integrations.
    Route::get('/settings/couriers', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'index'])->name('settings.couriers.index');
    Route::patch('/settings/couriers/{provider}', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'update'])->whereNumber('provider')->name('settings.couriers.update');
    Route::put('/settings/couriers/{provider}/rates', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'rates'])->whereNumber('provider')->name('settings.couriers.rates');
    Route::put('/settings/couriers/{provider}/credentials', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'credentials'])->whereNumber('provider')->name('settings.couriers.credentials');
    Route::get('/settings/delivery', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'delivery'])->name('settings.delivery.show');
    Route::put('/settings/delivery', [App\Http\Controllers\Api\V1\Admin\Settings\CourierProvidersController::class, 'updateDelivery'])->name('settings.delivery.update');
    // Booking panel charge preview (P6): read-only, from stored rate cards; orders.update + shipments.book.
    Route::post('/shipments/quote', App\Http\Controllers\Api\V1\Admin\ShipmentQuoteController::class)->name('shipments.quote');
    // Courier location mapping (P6, §3.6): a mirrored courier tree mapped BY HAND to our
    // districts/upazilas (rule 32). Reads → shipments.view; map/unmap/sync → shipments.manage_locations.
    Route::prefix('/courier-locations/{provider}')->where(['provider' => '[a-z][a-z0-9_]*'])->name('courier-locations.')->group(function (): void {
        $c = App\Http\Controllers\Api\V1\Admin\CourierLocationController::class;
        Route::get('/', [$c, 'overview'])->name('overview');
        Route::get('/locations', [$c, 'index'])->name('index');
        Route::get('/needs-mapping', [$c, 'needsMapping'])->name('needs-mapping');
        Route::get('/places', [$c, 'places'])->name('places');
        Route::get('/suggestions', [$c, 'suggestZones'])->name('suggestions');
        Route::get('/resolve', [$c, 'resolve'])->name('resolve');
        Route::post('/sync', [$c, 'sync'])->name('sync');
        Route::get('/locations/{location}/suggestions', [$c, 'suggestPlaces'])->whereNumber('location')->name('location.suggestions');
        Route::put('/locations/{location}/mapping', [$c, 'map'])->whereNumber('location')->name('location.map');
        Route::delete('/locations/{location}/mapping', [$c, 'unmap'])->whereNumber('location')->name('location.unmap');
    });
    // Shipping labels (P6): shipments.view + customers.view_pii; only once a consignment id exists.
    Route::post('/shipments/labels', [App\Http\Controllers\Api\V1\Admin\ShipmentLabelController::class, 'bulk'])->name('shipments.labels');
    Route::get('/shipments/{shipment}/label', [App\Http\Controllers\Api\V1\Admin\ShipmentLabelController::class, 'show'])->whereNumber('shipment')->name('shipments.label');

    // Finance ledger (P5): read-only browser, COD receivables, manual COD receipts (cash, not
    // revenue), expense categories, expenses (receipt, approval, pay, void). Static paths first.
    Route::get('/finance/ledger', [App\Http\Controllers\Api\V1\Admin\FinanceLedgerController::class, 'index'])->name('finance.ledger.index');
    Route::get('/finance/receivables', [App\Http\Controllers\Api\V1\Admin\FinanceLedgerController::class, 'receivables'])->name('finance.receivables');
    Route::post('/finance/orders/{order}/cod-receipts', [App\Http\Controllers\Api\V1\Admin\CodReceiptController::class, 'store'])->middleware('idempotent')->name('finance.cod-receipts.store');
    // Courier COD settlements (P6, §4.5): import a remittance (file or typed), review each
    // variance, confirm → ledger. Moving money, so import and confirm replay on a double submit.
    Route::get('/finance/settlements', [App\Http\Controllers\Api\V1\Admin\CourierSettlementController::class, 'index'])->name('finance.settlements.index');
    Route::post('/finance/settlements', [App\Http\Controllers\Api\V1\Admin\CourierSettlementController::class, 'store'])->middleware('idempotent')->name('finance.settlements.store');
    Route::get('/finance/settlements/{settlement}', [App\Http\Controllers\Api\V1\Admin\CourierSettlementController::class, 'show'])->whereNumber('settlement')->name('finance.settlements.show');
    Route::post('/finance/settlements/{settlement}/items/{item}/review', [App\Http\Controllers\Api\V1\Admin\CourierSettlementController::class, 'review'])->whereNumber('settlement')->whereNumber('item')->name('finance.settlements.items.review');
    Route::post('/finance/settlements/{settlement}/confirm', [App\Http\Controllers\Api\V1\Admin\CourierSettlementController::class, 'confirm'])->whereNumber('settlement')->middleware('idempotent')->name('finance.settlements.confirm');
    Route::get('/finance/expense-categories', [App\Http\Controllers\Api\V1\Admin\ExpenseCategoryController::class, 'index'])->name('finance.expense-categories.index');
    Route::post('/finance/expense-categories', [App\Http\Controllers\Api\V1\Admin\ExpenseCategoryController::class, 'store'])->name('finance.expense-categories.store');
    Route::patch('/finance/expense-categories/{category}', [App\Http\Controllers\Api\V1\Admin\ExpenseCategoryController::class, 'update'])->whereNumber('category')->name('finance.expense-categories.update');
    Route::delete('/finance/expense-categories/{category}', [App\Http\Controllers\Api\V1\Admin\ExpenseCategoryController::class, 'destroy'])->whereNumber('category')->name('finance.expense-categories.destroy');
    Route::get('/finance/expenses', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'index'])->name('finance.expenses.index');
    Route::get('/finance/expenses/summary', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'summary'])->name('finance.expenses.summary');
    Route::post('/finance/expenses', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'store'])->name('finance.expenses.store');
    Route::get('/finance/expenses/{expense}', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'show'])->whereNumber('expense')->name('finance.expenses.show');
    Route::patch('/finance/expenses/{expense}', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'update'])->whereNumber('expense')->name('finance.expenses.update');
    Route::delete('/finance/expenses/{expense}', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'destroy'])->whereNumber('expense')->name('finance.expenses.destroy');
    Route::post('/finance/expenses/{expense}/approve', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'approve'])->whereNumber('expense')->name('finance.expenses.approve');
    Route::post('/finance/expenses/{expense}/reject', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'reject'])->whereNumber('expense')->name('finance.expenses.reject');
    Route::post('/finance/expenses/{expense}/void', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'void'])->whereNumber('expense')->name('finance.expenses.void');
    Route::post('/finance/expenses/{expense}/pay', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'pay'])->whereNumber('expense')->name('finance.expenses.pay');
    Route::get('/finance/expenses/{expense}/receipt', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'receipt'])->whereNumber('expense')->name('finance.expenses.receipt.show');
    Route::post('/finance/expenses/{expense}/receipt', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'storeReceipt'])->whereNumber('expense')->name('finance.expenses.receipt.store');
    Route::delete('/finance/expenses/{expense}/receipt', [App\Http\Controllers\Api\V1\Admin\ExpenseController::class, 'destroyReceipt'])->whereNumber('expense')->name('finance.expenses.receipt.destroy');

    // Finance reports (P5): P&L statement + day/week/month/year series, profitability by
    // product / category / recommendation source, inventory valuation. Read-only; CSV exports.
    Route::get('/finance/reports/pnl', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'pnl'])->name('finance.reports.pnl');
    Route::get('/finance/reports/pnl/export', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'exportPnl'])->name('finance.reports.pnl.export');
    Route::get('/finance/reports/pnl/series', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'series'])->name('finance.reports.pnl.series');
    Route::get('/finance/reports/pnl/series/export', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'exportSeries'])->name('finance.reports.pnl.series.export');
    Route::get('/finance/reports/profitability', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'profitability'])->name('finance.reports.profitability');
    Route::get('/finance/reports/profitability/export', [App\Http\Controllers\Api\V1\Admin\FinanceReportController::class, 'exportProfitability'])->name('finance.reports.profitability.export');
    // "Owed back to a customer": refunds approved but not yet paid out (P7 follow-up).
    Route::get('/finance/reports/refunds-owed', App\Http\Controllers\Api\V1\Admin\FinanceRefundsOwedController::class)->name('finance.reports.refunds-owed');
    Route::get('/reports/inventory-valuation', [App\Http\Controllers\Api\V1\Admin\InventoryValuationController::class, 'index'])->name('reports.inventory-valuation');
    Route::get('/reports/inventory-valuation/export', [App\Http\Controllers\Api\V1\Admin\InventoryValuationController::class, 'export'])->name('reports.inventory-valuation.export');
    // Courier performance (P6): per provider and district, Dhaka dates; CSV like the other reports.
    Route::get('/reports/couriers', [App\Http\Controllers\Api\V1\Admin\CourierPerformanceController::class, 'index'])->name('reports.couriers');
    Route::get('/reports/couriers/export', [App\Http\Controllers\Api\V1\Admin\CourierPerformanceController::class, 'export'])->name('reports.couriers.export');
    // P8 sales analytics (Dhaka dates, Saturday weeks) + CSV/XLSX, and scheduled report emails.
    Route::get('/analytics/sales', [App\Http\Controllers\Api\V1\Admin\SalesAnalyticsController::class, 'index'])->name('analytics.sales');
    Route::get('/analytics/sales/export', [App\Http\Controllers\Api\V1\Admin\SalesAnalyticsController::class, 'export'])->name('analytics.sales.export');
    Route::get('/reports/schedules/recipients', [App\Http\Controllers\Api\V1\Admin\ReportScheduleController::class, 'recipients'])->name('reports.schedules.recipients');
    Route::get('/reports/schedules/{schedule}/runs', [App\Http\Controllers\Api\V1\Admin\ReportScheduleController::class, 'runs'])->whereNumber('schedule')->name('reports.schedules.runs');
    // P9: resend a failed run for the same period (every failed copy, or one recipient's).
    Route::post('/reports/schedules/{schedule}/runs/{run}/resend', [App\Http\Controllers\Api\V1\Admin\ReportScheduleController::class, 'resend'])
        ->whereNumber(['schedule', 'run'])->scopeBindings()->name('reports.schedules.runs.resend');
    Route::apiResource('/reports/schedules', App\Http\Controllers\Api\V1\Admin\ReportScheduleController::class)
        ->parameters(['schedules' => 'schedule'])->where(['schedule' => '[0-9]+'])->names('reports.schedules');

    // Fraud & risk (P6, checklist §2.4, rules 23/24/24b/33b): the verification queue (held
    // orders; a PERSON verifies or cancels — scoring never does), phone lookup with our score
    // and the courier panel kept separate, list status, and the Risk rules form.
    Route::get('/risk/queue', [App\Http\Controllers\Api\V1\Admin\RiskQueueController::class, 'index'])->name('risk.queue.index');
    Route::get('/risk/orders/{order}', [App\Http\Controllers\Api\V1\Admin\RiskQueueController::class, 'show'])->name('risk.orders.show');
    Route::post('/risk/orders/{order}/verify', [App\Http\Controllers\Api\V1\Admin\RiskQueueController::class, 'verify'])->name('risk.orders.verify');
    Route::post('/risk/orders/{order}/reject', [App\Http\Controllers\Api\V1\Admin\RiskQueueController::class, 'reject'])->name('risk.orders.reject');
    Route::post('/risk/orders/{order}/calls', [App\Http\Controllers\Api\V1\Admin\RiskQueueController::class, 'logCall'])->name('risk.orders.calls.store');
    Route::get('/risk/lookup', [App\Http\Controllers\Api\V1\Admin\RiskPhoneController::class, 'lookup'])->name('risk.lookup');
    Route::post('/risk/lookup/rescore', [App\Http\Controllers\Api\V1\Admin\RiskPhoneController::class, 'rescore'])->name('risk.lookup.rescore');
    Route::put('/risk/phone-status', [App\Http\Controllers\Api\V1\Admin\RiskPhoneController::class, 'setStatus'])->name('risk.phone-status');
    Route::get('/risk/rules', [App\Http\Controllers\Api\V1\Admin\RiskRulesController::class, 'show'])->name('risk.rules.show');
    Route::put('/risk/rules', [App\Http\Controllers\Api\V1\Admin\RiskRulesController::class, 'update'])->name('risk.rules.update');

    // Blog (Phase 7): posts, buying guides, categories, tags, authors — routes/api_v1_admin_blog.php.
    require __DIR__.'/api_v1_admin_blog.php';

    // Online-store content (Phase 7): pages, menus, banners, testimonials — routes/api_v1_admin_content.php.
    require __DIR__.'/api_v1_admin_content.php';

    // Homepage builder (Phase 7): CMS blocks, publish/versions/revert, preview token — routes/api_v1_admin_cms_blocks.php.
    require __DIR__.'/api_v1_admin_cms_blocks.php';

    // Customers (P8): list, profile, notes, customer analytics — routes/api_v1_admin_customers.php.
    require __DIR__.'/api_v1_admin_customers.php';
});
