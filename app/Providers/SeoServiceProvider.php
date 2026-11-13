<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Content\Listeners\RevalidateSeoPages;
use App\Domain\Seo\Actions\SaveSeoMeta;
use App\Domain\Seo\Contracts\SeoMetaStore;
use App\Domain\Seo\Contracts\SeoSubjectProvider;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Events\SlugChanged;
use App\Domain\Seo\Listeners\RecordSlugChanges;
use App\Domain\Seo\Services\SeoSubjects;
use App\Domain\Seo\Subjects\BrandSubjects;
use App\Domain\Seo\Subjects\CategorySubjects;
use App\Domain\Seo\Subjects\PageSubjects;
use App\Domain\Seo\Subjects\ProductSubjects;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * SEO module wiring (docs/api/seo.md): the subject providers for catalogue entities
 * and static pages (posts, guides and blog archives tag their own with SeoSubjectProvider::TAG),
 * the SeoMetaStore contract, slug-change
 * recording and storefront revalidation.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ProductSubjects::class, CategorySubjects::class, BrandSubjects::class, PageSubjects::class], SeoSubjectProvider::TAG);
        // Content writes its posts' seo_meta through this contract (no Content → Seo dependency).
        $this->app->bind(SeoMetaStore::class, SaveSeoMeta::class);
        $this->app->scoped(SeoSubjects::class);
    }

    public function boot(): void
    {
        Event::listen(SlugChanged::class, [RecordSlugChanges::class, 'onSlugChanged']);
        Event::listen(CategoryChanged::class, [RecordSlugChanges::class, 'onCategory']);
        Event::listen(BrandChanged::class, [RecordSlugChanges::class, 'onBrand']);
        Event::listen(SeoPagesStale::class, RevalidateSeoPages::class);
    }
}
