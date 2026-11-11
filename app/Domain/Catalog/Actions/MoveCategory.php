<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reparent and/or reorder a category: it becomes the child of `$parentId` (null =
 * a root) at sibling index `$position` (0-based, clamped to the end). One Action
 * covers the admin tree's up/down, indent and outdent.
 *
 * Refused with a 422 when the new parent is the category itself or one of its
 * descendants (a cycle), or when the move would push the subtree below three
 * levels; with a 409 when an active category would land under an archived parent.
 * The subtree moves with it, so every descendant's breadcrumbs are revalidated.
 */
final readonly class MoveCategory
{
    public function __construct(private CategoryTree $tree) {}

    public function handle(Category $category, ?int $parentId, int $position): Category
    {
        return DB::transaction(function () use ($category, $parentId, $position): Category {
            $this->tree->lock();

            $category = Category::query()->findOrFail($category->id);
            $oldParentId = $category->parent_id;
            $parent = $parentId !== null ? Category::query()->findOrFail($parentId) : null;

            if ($parent !== null && ($parent->id === $category->id || $parent->isDescendantOf($category))) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be moved under itself or one of its subcategories.']);
            }

            $newDepth = $parent === null ? 0 : $this->tree->depthOf($parent) + 1;
            if ($newDepth + $this->tree->heightBelow($category) > Category::MAX_DEPTH) {
                throw ValidationException::withMessages(['parent_id' => 'Categories can be nested at most three levels deep.']);
            }

            if ($parent !== null && $category->isActive() && ! $parent->isActive()) {
                throw CatalogConflict::parentArchived();
            }

            $siblings = Category::query()
                ->when($parent === null, static fn ($q) => $q->whereNull('parent_id'), static fn ($q) => $q->where('parent_id', $parent?->id))
                ->whereKeyNot($category->id)
                ->inTreeOrder()
                ->get();

            $position = max(0, $position);
            $before = $siblings->get($position);

            $currentIndex = Category::query()
                ->when($oldParentId === null, static fn ($q) => $q->whereNull('parent_id'), static fn ($q) => $q->where('parent_id', $oldParentId))
                ->where('_lft', '<', $category->getLft())
                ->count();

            if ($oldParentId === $parent?->id && $currentIndex === min($position, $siblings->count())) {
                return $category;
            }

            $last = $siblings->last();

            if ($before instanceof Category) {
                $category->insertBeforeNode($before);
            } elseif ($last instanceof Category) {
                // After the last sibling, not saveAsRoot(): that is a no-op for a node already at the root.
                $category->insertAfterNode($last);
            } elseif ($parent !== null) {
                $category->appendToNode($parent)->save();
            } else {
                $category->saveAsRoot();
            }

            $this->tree->renumber($parent?->id);
            if ($oldParentId !== $parent?->id) {
                $this->tree->renumber($oldParentId);
            }

            $category->refresh();

            CategoryChanged::dispatch($category->id, $category->slug, null, $this->tree->descendantSlugs($category));

            return $category;
        });
    }
}
