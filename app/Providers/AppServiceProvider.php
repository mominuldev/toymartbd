<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Services\ProductImageMediaUsages;
use App\Domain\Content\Listeners\RevalidateBrandPages;
use App\Domain\Content\Listeners\RevalidateCategoryPages;
use App\Domain\Content\Listeners\RevalidateProductPages;
use App\Domain\Content\Listeners\RevalidateStockPages;
use App\Domain\Customer\Contracts\CustomerOrderHistory;
use App\Domain\Customer\Listeners\RefreshAggregatesOnOrderChange;
use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Listeners\ForgetLowStockCount;
use App\Domain\Inventory\Services\StockAvailability;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderStatusTransition;
use App\Domain\Ordering\Listeners\SyncPaymentStatusOnPaymentChange;
use App\Domain\Ordering\Queries\EloquentCustomerOrderHistory;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Events\PaymentStatusChanged;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Support\ProductionSafety;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Catalog declares what it reads from Inventory; Inventory implements it (deptrac).
        $this->app->bind(AvailableStockQuery::class, StockAvailability::class);
        // P7: one flash-sale lookup per request, shared by every card and the product page.
        $this->app->scoped(\App\Domain\Marketing\Services\FlashSaleLookup::class);

        // DATABASE-DESIGN §5.1 step 1: free-shipping coupons and free-delivery promotions (P7) answer Fulfilment's seam.
        $this->app->bind(FreeShippingPromotion::class, \App\Domain\Marketing\Services\MarketingFreeShipping::class);

        // Customer declares what it reads from orders; Ordering implements it (deptrac).
        $this->app->bind(CustomerOrderHistory::class, EloquentCustomerOrderHistory::class);
        // Reviews ask Ordering whether a line is a delivered purchase (P4-CAT-12, deptrac).
        $this->app->bind(\App\Domain\Catalog\Reviews\Contracts\DeliveredPurchases::class, \App\Domain\Ordering\Queries\EloquentDeliveredPurchases::class);
        // The product import writes rows through the product FormRequests (P4-CAT-10).
        $this->app->bind(\App\Domain\Catalog\Import\Contracts\ProductRowWriter::class, \App\Http\Imports\FormRequestProductRowWriter::class);
        // first_order_only coupons read real order history (replaces NoCustomerOrderHistory).
        $this->app->bind(\App\Domain\Marketing\Contracts\CustomerOrderHistory::class, \App\Domain\Ordering\Queries\PhoneOrderHistory::class);
        // Birthday-reminder offers (P8 batch 3): Notification mints a personal coupon through this seam.
        $this->app->bind(\App\Domain\Marketing\Contracts\PersonalCouponIssuer::class, \App\Domain\Marketing\Actions\IssuePersonalCoupon::class);
        // The nightly stock reconciliation checks reserved against open orders (§7 invariant 5).
        $this->app->bind(\App\Domain\Inventory\Contracts\OpenOrderReservations::class, \App\Domain\Ordering\Queries\EloquentOpenOrderReservations::class);

        // Media usage resolver (docs/api/media-library.md): every place a media row can be
        // referenced is a tagged provider. Blog covers, pages and banners add a line here.
        $this->app->tag([ProductImageMediaUsages::class], MediaUsageProvider::TAG);
        // P9: the invoice logo chosen in Settings → Invoice details cannot be deleted while chosen.
        $this->app->tag([\App\Domain\Invoicing\Services\InvoiceLogoMediaUsages::class], MediaUsageProvider::TAG);
        $this->app->bind(MediaUsages::class, static fn ($app): MediaUsages => new MediaUsages($app->tagged(MediaUsageProvider::TAG)));

        // Payment drivers (rule 5): a new gateway is a driver class plus one line here.
        $this->app->singleton(PaymentGatewayRegistry::class, static function ($app): PaymentGatewayRegistry {
            $registry = new PaymentGatewayRegistry($app);
            $registry->register(PaymentMethod::Cod, CashOnDeliveryGateway::class);
            // Offered only while its payment_gateways row is active, configured and (in
            // production) verified — OnlineGateways (P4-PAY-02).
            $registry->register(PaymentMethod::Sslcommerz, \App\Domain\Payment\Gateways\Sslcommerz\SslcommerzGateway::class);

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // APP_DEBUG can never be on in production (P9 security review).
        ProductionSafety::enforce($this->app->isProduction(), $this->app->make('config'), $this->app->make('log'));

        // Domain event → listener wiring. Listeners outside app/Listeners are not
        // auto-discovered, so each is registered here.
        Event::listen(BrandChanged::class, RevalidateBrandPages::class);
        Event::listen(CategoryChanged::class, RevalidateCategoryPages::class);
        Event::listen(ProductChanged::class, RevalidateProductPages::class);
        Event::listen(StockLevelChanged::class, RevalidateStockPages::class);
        Event::listen(StockLevelChanged::class, ForgetLowStockCount::class);

        // Orders → customer aggregate projection (P4-ORD-10); every status transition
        // event implements OrderStatusTransition. Payments → order payment_status.
        Event::listen(OrderPlaced::class, RefreshAggregatesOnOrderChange::class);
        Event::listen(OrderStatusTransition::class, RefreshAggregatesOnOrderChange::class);
        Event::listen(PaymentStatusChanged::class, SyncPaymentStatusOnPaymentChange::class);
        // P7 marketing: a cancelled order gives back its coupon/promotion usage (flash units too);
        // promotion saves and start/end boundaries revalidate the storefront; a reminded cart
        // that becomes an order is recovered.
        Event::listen(\App\Domain\Ordering\Events\OrderCancelled::class, \App\Domain\Marketing\Listeners\ReverseUsageOnOrderCancelled::class);
        // P8 batch 2: an invoiced order cancelled, or a refund paid on one, gets a credit note.
        Event::listen(\App\Domain\Ordering\Events\OrderCancelled::class, \App\Domain\Invoicing\Listeners\IssueCreditNoteOnOrderCancelled::class);
        Event::listen(\App\Domain\Ordering\Events\RefundPaid::class, \App\Domain\Invoicing\Listeners\IssueCreditNoteOnRefundPaid::class);
        // P8 batch 3: an edit after issue gets a credit note / supplementary invoice; a courier return (RTO) a credit note.
        Event::listen(\App\Domain\Ordering\Events\OrderEdited::class, \App\Domain\Invoicing\Listeners\IssueDocumentsOnOrderEdited::class);
        Event::listen(\App\Domain\Ordering\Events\OrderReturned::class, \App\Domain\Invoicing\Listeners\IssueCreditNoteOnOrderReturned::class);
        Event::listen(\App\Domain\Marketing\Events\PromotionChanged::class, \App\Domain\Content\Listeners\RevalidatePromotionPages::class);
        Event::listen(OrderPlaced::class, \App\Domain\Notification\Listeners\MarkCartRecoveredOnOrderPlaced::class);
        // A payment flagged for review (rule 19) holds its order for a human (P4-PAY-03).
        Event::listen(\App\Domain\Payment\Events\PaymentFlaggedForReview::class, \App\Domain\Ordering\Listeners\HoldOrderOnPaymentReview::class);
    }
}
