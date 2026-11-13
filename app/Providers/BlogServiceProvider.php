<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogPostProduct;
use App\Domain\Content\Blog\Models\BlogPostSection;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Blog\Seo\BlogAuthorSeoSubjects;
use App\Domain\Content\Blog\Seo\BlogCategorySeoSubjects;
use App\Domain\Content\Blog\Seo\BlogPostSeoSubjects;
use App\Domain\Content\Blog\Seo\BlogTagSeoSubjects;
use App\Domain\Content\Blog\Services\BlogMediaUsages;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use App\Policies\BlogPostPolicy;
use App\Policies\BlogTermPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Blog wiring (Phase 7): policies (rule 21), cover/avatar media usages, SEO subjects, and
 * the scheduled-publish sweep every minute.
 */
final class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([BlogMediaUsages::class], MediaUsageProvider::TAG);
        // SEO module subjects (docs/api/seo.md §1): panel, storefront SEO document, sitemap, redirect checks.
        $this->app->tag([BlogPostSeoSubjects::class, BlogCategorySeoSubjects::class, BlogTagSeoSubjects::class, BlogAuthorSeoSubjects::class], SeoSubjectProvider::TAG);
    }

    public function boot(): void
    {
        Gate::policy(BlogPost::class, BlogPostPolicy::class);
        Gate::policy(BlogPostSection::class, BlogPostPolicy::class);
        Gate::policy(BlogPostProduct::class, BlogPostPolicy::class);
        Gate::policy(BlogCategory::class, BlogTermPolicy::class);
        Gate::policy(BlogTag::class, BlogTermPolicy::class);
        Gate::policy(BlogAuthor::class, BlogTermPolicy::class);

        // A scheduled post goes live at its instant; this makes the ISR cache follow within a minute.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('toymart:blog:publish-due')->everyMinute()->withoutOverlapping(5)->onOneServer();
        });
    }
}
