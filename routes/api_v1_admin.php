<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\AttributeValueController;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
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
    Route::get('/notifications/logs', [NotificationLogController::class, 'index'])->name('notifications.logs.index');
    Route::get('/notifications/logs/summary', [NotificationLogController::class, 'summary'])->name('notifications.logs.summary');
    Route::post('/notifications/logs/{log}/retry', [NotificationLogController::class, 'retry'])->whereNumber('log')->name('notifications.logs.retry');
    Route::get('/notifications/customers/{customer}/preferences', [NotificationPreferenceController::class, 'show'])->name('notifications.preferences.show');
    Route::put('/notifications/customers/{customer}/preferences', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');

    // Finance ledger (P5): read-only browser, COD receivables, manual COD receipts (cash, not
    // revenue), expense categories, expenses (receipt, approval, pay, void). Static paths first.
    Route::get('/finance/ledger', [App\Http\Controllers\Api\V1\Admin\FinanceLedgerController::class, 'index'])->name('finance.ledger.index');
    Route::get('/finance/receivables', [App\Http\Controllers\Api\V1\Admin\FinanceLedgerController::class, 'receivables'])->name('finance.receivables');
    Route::post('/finance/orders/{order}/cod-receipts', [App\Http\Controllers\Api\V1\Admin\CodReceiptController::class, 'store'])->middleware('idempotent')->name('finance.cod-receipts.store');
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
    Route::get('/reports/inventory-valuation', [App\Http\Controllers\Api\V1\Admin\InventoryValuationController::class, 'index'])->name('reports.inventory-valuation');
    Route::get('/reports/inventory-valuation/export', [App\Http\Controllers\Api\V1\Admin\InventoryValuationController::class, 'export'])->name('reports.inventory-valuation.export');
});
