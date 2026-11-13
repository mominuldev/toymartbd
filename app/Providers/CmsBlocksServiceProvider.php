<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\CmsBlockMediaUsages;
use App\Domain\Content\Events\CmsLayoutPublished;
use App\Domain\Content\Listeners\RevalidateCmsLayout;
use App\Policies\CmsBlockPolicy;
use App\Policies\CmsLayoutVersionPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** CMS block system / homepage builder (Phase 7): policies, block media usages and the publish → revalidate listener. */
final class CmsBlocksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Block images (hero slides, promo split) in the Media Library's "where used".
        $this->app->tag([CmsBlockMediaUsages::class], MediaUsageProvider::TAG);
    }

    public function boot(): void
    {
        Gate::policy(CmsBlock::class, CmsBlockPolicy::class);
        Gate::policy(CmsLayoutVersion::class, CmsLayoutVersionPolicy::class);

        Event::listen(CmsLayoutPublished::class, RevalidateCmsLayout::class);
    }
}
