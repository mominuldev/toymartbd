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
use App\Domain\Marketing\Services\CouponFreeShipping;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderStatusTransition;
use App\Domain\Ordering\Listeners\SyncPaymentStatusOnPaymentChange;
use App\Domain\Ordering\Queries\EloquentCustomerOrderHistory;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Events\PaymentStatusChanged;
use App\Domain\Payment\Gateways\CashOnDeliveryGateway;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
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

        // DATABASE-DESIGN §5.1 step 1: free-shipping coupons (Marketing) answer Fulfilment's seam.
        $this->app->bind(FreeShippingPromotion::class, CouponFreeShipping::class);

        // Customer declares what it reads from orders; Ordering implements it (deptrac).
        $this->app->bind(CustomerOrderHistory::class, EloquentCustomerOrderHistory::class);
        // Reviews ask Ordering whether a line is a delivered purchase (P4-CAT-12, deptrac).
        $this->app->bind(\App\Domain\Catalog\Reviews\Contracts\DeliveredPurchases::class, \App\Domain\Ordering\Queries\EloquentDeliveredPurchases::class);
        // The product import writes rows through the product FormRequests (P4-CAT-10).
        $this->app->bind(\App\Domain\Catalog\Import\Contracts\ProductRowWriter::class, \App\Http\Imports\FormRequestProductRowWriter::class);
        // first_order_only coupons read real order history (replaces NoCustomerOrderHistory).
        $this->app->bind(\App\Domain\Marketing\Contracts\CustomerOrderHistory::class, \App\Domain\Ordering\Queries\PhoneOrderHistory::class);

        // Media usage resolver (docs/api/media-library.md): every place a media row can be
        // referenced is a tagged provider. Blog covers, pages and banners add a line here.
        $this->app->tag([ProductImageMediaUsages::class], MediaUsageProvider::TAG);
        $this->app->bind(MediaUsages::class, static fn ($app): MediaUsages => new MediaUsages($app->tagged(MediaUsageProvider::TAG)));

        // Payment drivers (rule 5): a new gateway is a driver class plus one line here.
        $this->app->singleton(PaymentGatewayRegistry::class, static function ($app): PaymentGatewayRegistry {
            $registry = new PaymentGatewayRegistry($app);
            $registry->register(PaymentMethod::Cod, CashOnDeliveryGateway::class);

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
