<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Archive a category: it leaves the storefront menu and pages, but the row stays
 * (never hard-deleted — products reference it). Refused with a 409 while it has
 * active subcategories, so archiving never silently orphans a branch of the menu.
 * Idempotent: re-archiving changes nothing and fires nothing.
 */
final readonly class ArchiveCategory
{
    public function __construct(private CategoryTree $tree) {}

    public function handle(Category $category): Category
    {
        return DB::transaction(function () use ($category): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);

            if ($category->status === CategoryStatus::Archived) {
                return $category;
            }

            if ($this->tree->hasActiveChildren($category)) {
                throw CatalogConflict::categoryHasActiveChildren();
            }

            $category->status = CategoryStatus::Archived;
            $category->save();

            CategoryChanged::dispatch($category->id, $category->slug);

            return $category;
        });
    }
}
