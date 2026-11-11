<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Content\Listeners\RevalidateBrandPages;
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
    }
}
