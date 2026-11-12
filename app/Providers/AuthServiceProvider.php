<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderFinancial;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderNote;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Models\RefundItem;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use App\Models\Enums\Role;
use App\Models\User;
use App\Policies\BrandPolicy;
use App\Policies\CartPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CouponPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\OrderFinancialPolicy;
use App\Policies\OrderPartPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductAttributePolicy;
use App\Policies\ProductAttributeValuePolicy;
use App\Policies\ProductPartPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PromotionPolicy;
use App\Policies\RefundPolicy;
use App\Policies\SeoPolicy;
use App\Policies\StockPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Every model has a Policy (CLAUDE.md rule 21). Registered explicitly rather
     * than left to auto-discovery, so a missing one is visible in review.
     *
     * @var array<class-string, class-string>
     */
    private const array POLICIES = [
        User::class => UserPolicy::class,
        Brand::class => BrandPolicy::class,
        Category::class => CategoryPolicy::class,
        ProductAttribute::class => ProductAttributePolicy::class,
        ProductAttributeValue::class => ProductAttributeValuePolicy::class,
        Product::class => ProductPolicy::class,
        ProductVariant::class => ProductPartPolicy::class,
        ProductImage::class => ProductPartPolicy::class,
        ProductRelation::class => ProductPartPolicy::class,
        StockLevel::class => StockPolicy::class,
        StockMovement::class => StockPolicy::class,
        StockLocation::class => StockPolicy::class,
        Customer::class => CustomerPolicy::class,
        Address::class => CustomerPolicy::class,
        Order::class => OrderPolicy::class,
        OrderItem::class => OrderPartPolicy::class,
        OrderEvent::class => OrderPartPolicy::class,
        OrderNote::class => OrderPartPolicy::class,
        OrderFinancial::class => OrderFinancialPolicy::class,
        Payment::class => PaymentPolicy::class,
        Refund::class => RefundPolicy::class,
        RefundItem::class => RefundPolicy::class,
        Cart::class => CartPolicy::class,
        CartItem::class => CartPolicy::class,
        Coupon::class => CouponPolicy::class,
        CouponUsage::class => CouponPolicy::class,
        Promotion::class => PromotionPolicy::class,
        StockAdjustment::class => StockPolicy::class,
        StockAdjustmentItem::class => StockPolicy::class,
        SeoMeta::class => SeoPolicy::class,
        SlugHistory::class => SeoPolicy::class,
    ];

    /**
     * super_admin — and only super_admin — passes every gate and policy check
     * (SYSTEM-ARCHITECTURE security table). Returning null for everyone else defers
     * to spatie/laravel-permission and the model policies.
     * Deactivated staff never get this far: EnsureActiveStaffSession refuses them
     * on every admin route.
     */
    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(static function (mixed $user): ?bool {
            return $user instanceof User && $user->hasRole(Role::SuperAdmin) ? true : null;
        });
    }
}
