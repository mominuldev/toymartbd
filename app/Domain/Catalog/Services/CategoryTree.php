<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Nested-set helpers shared by the category Actions. No transaction of its own —
 * every method composes inside an Action's transaction (rule 3).
 */
final class CategoryTree
{
    /**
     * Serialise tree writes. A nested-set insert or move rewrites `_lft`/`_rgt`
     * across the table, so two concurrent writers would corrupt it; categories are
     * few and written rarely, so locking every row is cheap.
     */
    public function lock(): void
    {
        Category::withTrashed()->lockForUpdate()->select('id')->get();
    }

    /** 0 for a root category. */
    public function depthOf(Category $category): int
    {
        return Category::query()->treeAncestorsOf($category)->count();
    }

    /** How many levels sit below the category (0 for a leaf). */
    public function heightBelow(Category $category): int
    {
        $own = $this->depthOf($category);

        /** @var int|null $deepest */
        $deepest = Category::query()->withTreeDepth()->treeDescendantsOf($category)->get()->max('depth');

        return $deepest === null ? 0 : (int) $deepest - $own;
    }

    public function hasActiveChildren(Category $category): bool
    {
        return Category::query()->treeDescendantsOf($category)->where('status', CategoryStatus::Active->value)->exists();
    }

    /** @return list<string> */
    public function descendantSlugs(Category $category): array
    {
        return array_values(Category::query()->treeDescendantsOf($category)->inTreeOrder()->pluck('slug')->all());
    }

    /**
     * Nest a flat, tree-ordered (`_lft`) list into roots with their `children`
     * relation set. A node whose parent is not in the list is dropped with its whole
     * subtree — so filtering out a hidden or archived category also hides what sits
     * under it, rather than promoting its children to roots. Nodes deeper than
     * `$maxDepth` (root = 0) are dropped too.
     *
     * @param  iterable<Category>  $ordered
     * @return EloquentCollection<int, Category>
     */
    public function nest(iterable $ordered, int $maxDepth = Category::MAX_DEPTH, ?int $rootParentId = null): EloquentCollection
    {
        /** @var array<int, array{node: Category, depth: int}> $included */
        $included = [];
        $roots = new EloquentCollection;

        foreach ($ordered as $node) {
            $node->setRelation('children', new EloquentCollection);

            if ($node->parent_id === $rootParentId) {
                $depth = 0;
                $roots->push($node);
            } elseif ($node->parent_id !== null && isset($included[$node->parent_id]) && $included[$node->parent_id]['depth'] < $maxDepth) {
                $depth = $included[$node->parent_id]['depth'] + 1;
                /** @var EloquentCollection<int, Category> $siblings */
                $siblings = $included[$node->parent_id]['node']->getRelation('children');
                $siblings->push($node);
            } else {
                continue;
            }

            $included[$node->id] = ['node' => $node, 'depth' => $depth];
        }

        return $roots;
    }

    /**
     * Keep `position` equal to the sibling index (the nested-set order) under one
     * parent, so single-level readers see the same order as the tree.
     */
    public function renumber(?int $parentId): void
    {
        $siblings = Category::query()
            ->when($parentId === null, static fn ($q) => $q->whereNull('parent_id'), static fn ($q) => $q->where('parent_id', $parentId))
            ->inTreeOrder()
            ->get(['id', 'position']);

        foreach ($siblings as $index => $sibling) {
            if ($sibling->position !== $index) {
                Category::query()->whereKey($sibling->id)->update(['position' => $index]);
            }
        }
    }
}
