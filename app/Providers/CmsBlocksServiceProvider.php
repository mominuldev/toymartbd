<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Events\CmsLayoutPublished;
use App\Domain\Content\Listeners\RevalidateCmsLayout;
use App\Policies\CmsBlockPolicy;
use App\Policies\CmsLayoutVersionPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** CMS block system / homepage builder (Phase 7): policies and the publish → revalidate listener. */
final class CmsBlocksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(CmsBlock::class, CmsBlockPolicy::class);
        Gate::policy(CmsLayoutVersion::class, CmsLayoutVersionPolicy::class);

        Event::listen(CmsLayoutPublished::class, RevalidateCmsLayout::class);
    }
}
