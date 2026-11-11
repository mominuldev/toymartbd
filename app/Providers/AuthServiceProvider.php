<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Role;
use App\Models\User;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ProductAttributePolicy;
use App\Policies\ProductAttributeValuePolicy;
use App\Policies\ProductPartPolicy;
use App\Policies\ProductPolicy;
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
