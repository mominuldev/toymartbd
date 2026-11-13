<?php

declare(strict_types=1);

namespace App\Domain\Seo\Listeners;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Seo\Actions\RecordSlugChange;
use App\Domain\Seo\Enums\SeoableType;
use App\Domain\Seo\Events\SlugChanged;

/**
 * Slug changes of every entity but products (which record theirs in their own
 * transaction) → `slug_history`, so the old URL 301s (docs/api/seo.md §1).
 * Categories and brands: from Catalog's after-commit events (`previousSlug`); the old
 * URL counts as live when the entity is active. Pages, posts, guides: {@see SlugChanged}.
 */
final readonly class RecordSlugChanges
{
    public function __construct(private RecordSlugChange $record) {}

    public function onSlugChanged(SlugChanged $event): void
    {
        $this->record->handle($event->type, $event->id, $event->oldSlug, $event->newSlug, $event->wasLive, $event->actorId);
    }

    public function onCategory(CategoryChanged $event): void
    {
        if ($event->previousSlug === null || $event->previousSlug === $event->slug) {
            return;
        }

        $live = Category::query()->whereKey($event->categoryId)->where('status', CategoryStatus::Active->value)->exists();
        $this->record->handle(SeoableType::Category->value, $event->categoryId, $event->previousSlug, $event->slug, $live);
    }

    public function onBrand(BrandChanged $event): void
    {
        if ($event->previousSlug === null || $event->previousSlug === $event->slug) {
            return;
        }

        $live = Brand::query()->whereKey($event->brandId)->where('status', BrandStatus::Active->value)->exists();
        $this->record->handle(SeoableType::Brand->value, $event->brandId, $event->previousSlug, $event->slug, $live);
    }
}
