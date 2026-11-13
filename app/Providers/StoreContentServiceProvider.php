<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Events\MenusChanged;
use App\Domain\Content\Events\TestimonialsChanged;
use App\Domain\Content\Listeners\RevalidateStoreContent;
use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\Testimonial;
use App\Domain\Content\Services\BannerMediaUsages;
use App\Domain\Content\Services\TestimonialMediaUsages;
use App\Policies\BannerPolicy;
use App\Policies\CmsPagePolicy;
use App\Policies\MenuPolicy;
use App\Policies\TestimonialPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Online-store content (Phase 7): static pages, menus, banners, testimonials — their policies,
 * media usages and storefront cache revalidation.
 */
final class StoreContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([BannerMediaUsages::class, TestimonialMediaUsages::class], MediaUsageProvider::TAG);
    }

    public function boot(): void
    {
        Gate::policy(CmsPage::class, CmsPagePolicy::class);
        Gate::policy(Menu::class, MenuPolicy::class);
        Gate::policy(Banner::class, BannerPolicy::class);
        Gate::policy(Testimonial::class, TestimonialPolicy::class);

        Event::listen(CmsPageChanged::class, RevalidateStoreContent::class);
        Event::listen(MenusChanged::class, RevalidateStoreContent::class);
        Event::listen(BannersChanged::class, RevalidateStoreContent::class);
        Event::listen(TestimonialsChanged::class, RevalidateStoreContent::class);
    }
}
