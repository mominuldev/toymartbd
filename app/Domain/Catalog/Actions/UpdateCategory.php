<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryTree;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit a category's own fields. Only the keys present in `$changes` are written;
 * the slug changes only when given explicitly. The parent and sibling order are
 * MoveCategory's job, never this one's.
 *
 * `is_active: false` follows ArchiveCategory's rule (refused while active children
 * remain); `is_active: true` is refused under an archived parent, because the
 * category would be "active" yet unreachable.
 */
final readonly class UpdateCategory
{
    private const array FIELDS = ['name', 'name_bn', 'slug', 'description', 'description_bn', 'icon', 'is_featured', 'show_in_menu'];

    public function __construct(private CategoryTree $tree) {}

    /**
     * @param  array{name?: string, name_bn?: string|null, slug?: string, description?: string|null, description_bn?: string|null, icon?: string|null, is_featured?: bool, show_in_menu?: bool, is_active?: bool}  $changes
     */
    public function handle(Category $category, array $changes): Category
    {
        try {
            return DB::transaction(function () use ($category, $changes): Category {
                $category = Category::query()->lockForUpdate()->findOrFail($category->id);
                $previousSlug = $category->slug;
                $previousName = [$category->name, $category->name_bn];

                $category->fill(array_intersect_key($changes, array_flip(self::FIELDS)));

                if (array_key_exists('is_active', $changes)) {
                    $category->status = $changes['is_active'] ? CategoryStatus::Active : CategoryStatus::Archived;
                }

                if (! $category->isDirty()) {
                    return $category;
                }

                if ($category->isDirty('status')) {
                    if ($category->status === CategoryStatus::Archived && $this->tree->hasActiveChildren($category)) {
                        throw CatalogConflict::categoryHasActiveChildren();
                    }

                    if ($category->status === CategoryStatus::Active && $category->parent_id !== null
                        && Category::query()->find($category->parent_id)?->isActive() === false) {
                        throw CatalogConflict::parentArchived();
                    }
                }

                $category->save();

                // A rename or slug change alters the breadcrumbs of everything below it.
                $breadcrumbsChanged = $category->slug !== $previousSlug || [$category->name, $category->name_bn] !== $previousName;

                CategoryChanged::dispatch(
                    $category->id,
                    $category->slug,
                    $category->slug !== $previousSlug ? $previousSlug : null,
                    $breadcrumbsChanged ? $this->tree->descendantSlugs($category) : [],
                );

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
