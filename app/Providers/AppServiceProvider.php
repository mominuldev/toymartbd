<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Content\Listeners\RevalidateBrandPages;
use App\Domain\Content\Listeners\RevalidateCategoryPages;
use App\Domain\Content\Listeners\RevalidateProductPages;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
