<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\CategoryData;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategorySlugs;
use App\Domain\Catalog\Services\CategoryTree;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a category as the last child of its parent (or the last root). The tree
 * is at most three levels deep (Category::MAX_DEPTH); an active category cannot
 * be created under an archived parent. CategoryChanged fires after commit.
 */
final readonly class CreateCategory
{
    public function __construct(private CategorySlugs $slugs, private CategoryTree $tree) {}

    public function handle(CategoryData $data): Category
    {
        try {
            return DB::transaction(function () use ($data): Category {
                $this->tree->lock();

                $parent = $data->parentId !== null ? Category::query()->findOrFail($data->parentId) : null;

                if ($parent !== null && $this->tree->depthOf($parent) >= Category::MAX_DEPTH) {
                    throw ValidationException::withMessages(['parent_id' => 'Categories can be nested at most three levels deep.']);
                }

                if ($parent !== null && $data->isActive && ! $parent->isActive()) {
                    throw CatalogConflict::parentArchived();
                }

                $category = new Category([
                    'name' => $data->name,
                    'name_bn' => $data->nameBn,
                    'slug' => $data->slug ?? $this->slugs->generate($data->name, $data->nameBn),
                    'description' => $data->description,
                    'description_bn' => $data->descriptionBn,
                    'icon' => $data->icon,
                    'is_featured' => $data->isFeatured,
                    'show_in_menu' => $data->showInMenu,
                    'status' => $data->isActive ? CategoryStatus::Active : CategoryStatus::Archived,
                ]);

                if ($parent !== null) {
                    $category->appendToNode($parent)->save();
                } else {
                    $category->saveAsRoot();
                }

                $this->tree->renumber($parent?->id);

                CategoryChanged::dispatch($category->id, $category->slug);

                return $category->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }
}
